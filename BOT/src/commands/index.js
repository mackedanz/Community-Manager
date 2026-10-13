// Alle Slash-Befehle des Bots. Neue Befehle hier eintragen; register.js meldet sie bei Discord an.
import * as ping from './ping.js';

export const commands = [ping];

export const byName = new Map(commands.map((c) => [c.data.name, c]));
