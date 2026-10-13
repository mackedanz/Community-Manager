// Startpunkt des Bots: verbindet sich mit der Datenbank und mit Discord und verteilt die Slash-Befehle.
import { Client, Events, GatewayIntentBits, MessageFlags } from 'discord.js';
import { readConfig, ConfigError } from './config.js';
import { createPool, checkDatabase } from './db.js';
import { byName } from './commands/index.js';
import * as panel from './commands/panel.js';
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

const client = new Client({ intents: [GatewayIntentBits.Guilds] });

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
  await client.destroy();
  await pool.end();
}
process.once('SIGINT', () => shutdown('SIGINT'));
process.once('SIGTERM', () => shutdown('SIGTERM'));

try {
  await client.login(config.token);
} catch (err) {
  log.error(err.code === 'TokenInvalid'
    ? 'Discord lehnt DISCORD_BOT_TOKEN ab. Token im Developer Portal (Bot -> Reset Token) neu erzeugen und eintragen.'
    : 'Anmeldung bei Discord fehlgeschlagen', err.code === 'TokenInvalid' ? undefined : err);
  await client.destroy();
  await pool.end();
  process.exitCode = 1;
}
