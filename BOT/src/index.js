// Startpunkt des Bots: verbindet sich mit der Datenbank und mit Discord und verteilt die Slash-Befehle.
import { Client, Events, GatewayIntentBits, MessageFlags } from 'discord.js';
import { readConfig, ConfigError } from './config.js';
import { createPool, checkDatabase } from './db.js';
import { byName } from './commands/index.js';
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

client.on(Events.InteractionCreate, async (interaction) => {
  if (!interaction.isChatInputCommand()) return;
  const command = byName.get(interaction.commandName);
  if (!command) {
    log.warn(`Unbekannter Befehl /${interaction.commandName}`);
    return;
  }
  try {
    await command.execute(interaction, { pool, config });
  } catch (err) {
    log.error(`/${interaction.commandName} fehlgeschlagen`, err);
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
