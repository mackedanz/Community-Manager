// Startpunkt des Bots: verbindet sich mit der Datenbank und mit Discord und verteilt die Slash-Befehle.
import { Client, Events, GatewayIntentBits, MessageFlags, Partials } from 'discord.js';
import { readConfig, ConfigError } from './config.js';
import { createPool, checkDatabase } from './db.js';
import { byName } from './commands/index.js';
import * as panel from './commands/panel.js';
import { registerMemberEvents } from './events/members.js';
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
const client = new Client({
  intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers],
  // Änderungen an Mitgliedern melden, auch wenn ihr alter Stand nicht im Cache ist
  partials: [Partials.GuildMember],
});
const memberSync = registerMemberEvents(client, { pool, config, log });

client.once(Events.ClientReady, (c) => {
  log.info(`Angemeldet als ${c.user.tag} auf ${c.guilds.cache.size} Server(n)`);
});

/** Welche Funktion bearbeitet diese Interaktion? null = keine (wird ignoriert). */
function handlerFor(interaction) {
  if (!interaction.inGuild()) return null;
  if (interaction.isChatInputCommand()) {
    const command = byName.get(interaction.commandName);
    if (!command) log.warn(`Unbekannter Befehl /${interaction.commandName}`);
    return command ? { name: `/${interaction.commandName}`, run: command.execute } : null;
  }
  if ((interaction.isRoleSelectMenu() || interaction.isButton()) && panel.handles(interaction.customId)) {
    return { name: `Panel ${interaction.customId}`, run: panel.handleComponent };
  }
  return null;
}

client.on(Events.InteractionCreate, async (interaction) => {
  const handler = handlerFor(interaction);
  if (handler === null) return;
  try {
    await handler.run(interaction, { pool, config });
  } catch (err) {
    log.error(`${handler.name} fehlgeschlagen`, err);
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
    log.error('Discord verweigert den Zugriff auf die Mitglieder. Im Developer Portal unter Bot den "Server Members Intent" einschalten.');
  } else {
    log.error('Anmeldung bei Discord fehlgeschlagen', err);
  }
  memberSync.stop();
  await client.destroy();
  await pool.end();
  process.exitCode = 1;
}
