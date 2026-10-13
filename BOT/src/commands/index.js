// Alle Slash-Befehle des Bots. Neue Befehle hier eintragen; register.js meldet sie bei Discord an.
import * as abgleichen from './abgleichen.js';
import * as ausbildung from './ausbildung.js';
import * as design from './design.js';
import * as einrichten from './einrichten.js';
import { makeHelp } from './help.js';
import * as panel from './panel.js';
import * as ping from './ping.js';
import * as serverregeln from './serverregeln.js';
import * as termine from './termine.js';
import * as termineKanal from './termine-kanal.js';

export const commands = [einrichten, design, abgleichen, termine, termineKanal, ausbildung, serverregeln, ping];
// /hilfe und /help zeigen alle Befehle dieser Liste (auch sich selbst)
commands.push(
  makeHelp('hilfe', 'Übersicht aller Befehle des Bots (auch als /help)', () => commands),
  makeHelp('help', 'Übersicht aller Befehle des Bots (auch als /hilfe)', () => commands),
);

export const byName = new Map(commands.map((c) => [c.data.name, c]));

/** Module mit Schaltflächen, Auswahlmenüs oder Eingabefenstern: handles(customId) und handleComponent(). */
export const componentOwners = [panel, termine, serverregeln];
