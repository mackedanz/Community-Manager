// Alle Slash-Befehle des Bots. Neue Befehle hier eintragen; register.js meldet sie bei Discord an.
import * as abgleichen from './abgleichen.js';
import * as design from './design.js';
import * as einrichten from './einrichten.js';
import * as ping from './ping.js';

export const commands = [einrichten, design, abgleichen, ping];

export const byName = new Map(commands.map((c) => [c.data.name, c]));
