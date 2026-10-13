// Meldet die Slash-Befehle bei Discord an. Nutzung:
//   npm run register              -> nur auf dem Server BOT_DEV_GUILD_ID (sofort sichtbar, zum Testen)
//   npm run register -- --global  -> für alle Server der Anwendung
// --global ersetzt alle globalen Befehle der Anwendung durch die Befehle dieses Bots.
import { REST, Routes } from 'discord.js';
import { readConfig, ConfigError } from './config.js';
import { commands } from './commands/index.js';

let config;
try {
  config = readConfig();
} catch (err) {
  if (!(err instanceof ConfigError)) throw err;
  console.error(err.message);
  process.exit(1);
}

const global = process.argv.includes('--global');
if (!global && !config.devGuildId) {
  console.error('BOT_DEV_GUILD_ID ist nicht gesetzt. Entweder die ID des Testservers eintragen oder bewusst --global angeben.');
  process.exit(1);
}

const rest = new REST().setToken(config.token);
const body = commands.map((c) => c.data);
const route = global
  ? Routes.applicationCommands(config.applicationId)
  : Routes.applicationGuildCommands(config.applicationId, config.devGuildId);

await rest.put(route, { body });
console.log(`${body.length} Befehl(e) registriert ${global ? 'für alle Server' : `auf Server ${config.devGuildId}`}: ${body.map((c) => '/' + c.name).join(', ')}`);
