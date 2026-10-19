// Startpunkt des Bots: verbindet sich mit der Datenbank und mit Discord und verteilt die Slash-Befehle.
import { Client, Events, GatewayIntentBits, MessageFlags, Options, Partials } from 'discord.js';
import { readConfig, ConfigError } from './config.js';
import { createPool, checkDatabase, orgForGuild } from './db.js';
import { MESSAGE_CONTENT_STATUS, setSetting, TWITCH_STATUS } from './lib/settings.js';
import { createAutoDelete } from './lib/autodelete.js';
import { createTwitch } from './lib/twitch.js';
import { startTwitchWatch } from './workers/twitch.js';
import { byName, componentOwners } from './commands/index.js';
import { registerAutomod } from './events/automod.js';
import { registerMemberEvents } from './events/members.js';
import { registerMessageLog } from './events/messagelog.js';
import { registerModLog } from './events/modlog.js';
import { registerTempVoice } from './events/tempvoice.js';
import { registerWelcome } from './events/welcome.js';
import { startAnnouncer } from './workers/announcer.js';
import { startCreatorWatch } from './workers/creators.js';
import { log } from './log.js';

let config;
try {
  config = readConfig();
} catch (err) {
  if (!(err instanceof ConfigError)) throw err;
  log.error(err.message);
  process.exit(1);
}

const pool = createPool(config.db);
try {
  const db = await checkDatabase(pool);
  log.info(`Datenbank verbunden (${config.db.host}:${config.db.port}/${config.db.database}), Stand ${db.migration ?? 'ohne Migrationen'}`);
} catch (err) {
  // Kein Abbruch: die Datenbank kann nach einem Neustart des Stacks später bereitstehen
  log.warn(`Datenbank beim Start nicht erreichbar: ${err.code ?? err.message}`);
}

// GuildMembers ist ein privilegierter Intent: im Developer Portal unter Bot -> "Server Members Intent" einschalten
// Nachrichten lesen (Automod, Nachrichten-Protokoll) nur mit BOT_MESSAGE_CONTENT=1: "Message Content" ist ebenfalls privilegiert
const intents = [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers, GatewayIntentBits.GuildVoiceStates];
if (config.messageContent) intents.push(GatewayIntentBits.GuildMessages, GatewayIntentBits.MessageContent);
const client = new Client({
  intents,
  // Änderungen an Mitgliedern melden, auch wenn ihr alter Stand nicht im Cache ist
  partials: [Partials.GuildMember],
  // Nachrichten nur begrenzt im Speicher halten (für das Protokoll gelöschter und geänderter Nachrichten)
  makeCache: Options.cacheWithLimits({ ...Options.DefaultMakeCacheSettings, MessageManager: 200 }),
});
const memberSync = registerMemberEvents(client, { pool, config, log });
// Beitritte und Austritte im Moderations-Protokoll
registerModLog(client, { pool, log });
// Willkommensnachricht für neue Mitglieder
registerWelcome(client, { pool, log });
// Temporäre Sprachkanäle (Auslöser-Kanal per /sprachkanal festlegen)
const tempVoice = registerTempVoice(client, { pool, log });
if (config.messageContent) {
  registerAutomod(client, { pool, log });
  registerMessageLog(client, { pool, log });
} else {
  log.info('Automod und Nachrichten-Protokoll sind aus (BOT_MESSAGE_CONTENT=1 setzen und "Message Content Intent" im Developer Portal einschalten)');
}
// Terminankündigungen und Erinnerungen (jede Minute)
const announcer = startAnnouncer(client, { pool, config, log });
// Neue Videos der Creator (alle 10 Minuten)
const creatorWatch = startCreatorWatch(client, { pool, log });
// Twitch-Live-Meldungen (alle 2 Minuten, nur mit TWITCH_CLIENT_ID und TWITCH_CLIENT_SECRET)
const twitchWatch = startTwitchWatch(client, { pool, log, twitch: config.twitch ? createTwitch(config.twitch) : null });

client.once(Events.ClientReady, async (c) => {
  log.info(`Angemeldet als ${c.user.tag} auf ${c.guilds.cache.size} Server(n)`);
  // Der Web-App mitteilen, ob Automod und Nachrichten-Protokoll laufen (sie zeigt sonst einen Hinweis)
  for (const guild of c.guilds.cache.values()) {
    try {
      const org = await orgForGuild(pool, guild.id);
      if (org) {
        await setSetting(pool, org.id, MESSAGE_CONTENT_STATUS, config.messageContent ? '1' : '0');
        await setSetting(pool, org.id, TWITCH_STATUS, config.twitch ? '1' : '0');
      }
    } catch (err) {
      log.warn(`Status für ${guild.id} konnte nicht gespeichert werden: ${err.code ?? err.message}`);
    }
  }
});

/** Welche Funktion bearbeitet diese Interaktion? null = keine (wird ignoriert). */
function handlerFor(interaction) {
  if (!interaction.inGuild()) return null;
  // Vorschläge beim Tippen (z. B. Schiffsnamen bei /wer-hat)
  if (interaction.isAutocomplete()) {
    const command = byName.get(interaction.commandName);
    return command?.autocomplete ? { name: `Vorschläge /${interaction.commandName}`, run: command.autocomplete } : null;
  }
  if (interaction.isChatInputCommand()) {
    const command = byName.get(interaction.commandName);
    if (!command) log.warn(`Unbekannter Befehl /${interaction.commandName}`);
    return command ? { name: `/${interaction.commandName}`, run: command.execute } : null;
  }
  // Schaltflächen, Auswahlmenüs und Eingabefenster: zuständig ist das Modul, dessen handles() die custom_id erkennt
  if (interaction.isMessageComponent() || interaction.isModalSubmit()) {
    const owner = componentOwners.find((m) => m.handles(interaction.customId));
    return owner ? { name: `Aktion ${interaction.customId}`, run: owner.handleComponent } : null;
  }
  return null;
}

// Private Antworten (nur für die Person sichtbar) nach 10 Minuten ohne Aktion löschen
const autoDelete = createAutoDelete();

client.on(Events.InteractionCreate, async (interaction) => {
  const handler = handlerFor(interaction);
  if (handler === null) return;
  try {
    await handler.run(interaction, { pool, config, log });
    await autoDelete.track(interaction);
  } catch (err) {
    log.error(`${handler.name} fehlgeschlagen`, err);
    // Auf Vorschläge beim Tippen kann man nicht mit einer Nachricht antworten
    if (interaction.isAutocomplete()) return;
    const reply = { content: 'Da ist etwas schiefgelaufen. Bitte später noch einmal versuchen.', flags: MessageFlags.Ephemeral };
    try {
      if (interaction.deferred || interaction.replied) await interaction.editReply(reply.content);
      else await interaction.reply(reply);
    } catch {
      // Antwort ist nicht mehr möglich (z. B. abgelaufen); der Fehler steht bereits im Log
    }
  }
});

client.on(Events.Error, (err) => log.error('Discord-Fehler', err));

async function shutdown(signal) {
  log.info(`${signal} erhalten, Bot wird beendet`);
  memberSync.stop();
  announcer.stop();
  creatorWatch.stop();
  twitchWatch.stop();
  autoDelete.stop();
  tempVoice.stop();
  await client.destroy();
  await pool.end();
}
process.once('SIGINT', () => shutdown('SIGINT'));
process.once('SIGTERM', () => shutdown('SIGTERM'));

try {
  await client.login(config.token);
} catch (err) {
  if (err.code === 'TokenInvalid') {
    log.error('Discord lehnt DISCORD_BOT_TOKEN ab. Token im Developer Portal (Bot -> Reset Token) neu erzeugen und eintragen.');
  } else if (/disallowed intents/i.test(String(err.message)) || err.code === 4014) {
    log.error('Discord verweigert den Zugriff auf Mitglieder oder Nachrichten. Im Developer Portal unter Bot den "Server Members Intent" einschalten'
      + (config.messageContent ? ' und den "Message Content Intent" (oder BOT_MESSAGE_CONTENT aus der .env entfernen).' : '.'));
  } else {
    log.error('Anmeldung bei Discord fehlgeschlagen', err);
  }
  memberSync.stop();
  announcer.stop();
  creatorWatch.stop();
  twitchWatch.stop();
  await client.destroy();
  await pool.end();
  process.exitCode = 1;
}
