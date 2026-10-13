// Alle Slash-Befehle des Bots. Neue Befehle hier eintragen; register.js meldet sie bei Discord an.
import * as abgleichen from './abgleichen.js';
import * as design from './design.js';
import * as einrichten from './einrichten.js';
import * as panel from './panel.js';
import * as ping from './ping.js';
import * as serverregeln from './serverregeln.js';
import * as termine from './termine.js';

export const commands = [einrichten, design, abgleichen, termine, serverregeln, ping];

export const byName = new Map(commands.map((c) => [c.data.name, c]));

/** Module mit Schaltflächen, Auswahlmenüs oder Eingabefenstern: handles(customId) und handleComponent(). */
export const componentOwners = [panel, termine, serverregeln];
