// Alle Slash-Befehle des Bots. Neue Befehle hier eintragen; register.js meldet sie bei Discord an.
import * as abgleichen from './abgleichen.js';
import * as ausbildung from './ausbildung.js';
import * as automod from './automod.js';
import * as betreiber from './betreiber.js';
import * as creator from './creator.js';
import * as design from './design.js';
import * as einrichten from './einrichten.js';
import * as flotte from './flotte.js';
import * as hangar from './hangar.js';
import * as mod from './mod.js';
import * as modEinstellungen from './mod-einstellungen.js';
import * as orga from './orga.js';
import { makeHelp } from './help.js';
import * as panel from './panel.js';
import * as ping from './ping.js';
import * as profil from './profil.js';
import * as serverregeln from './serverregeln.js';
import * as terminNeu from './termin-neu.js';
import * as termine from './termine.js';
import * as termineKanal from './termine-kanal.js';
import * as werHat from './wer-hat.js';

export const commands = [einrichten, design, abgleichen, termine, terminNeu, termineKanal, ausbildung, hangar, flotte, werHat, profil, orga, creator, mod, modEinstellungen, automod, serverregeln, betreiber, ping];
// /hilfe und /help zeigen alle Befehle dieser Liste (auch sich selbst)
commands.push(
  makeHelp('hilfe', 'Übersicht aller Befehle des Bots (auch als /help)', () => commands),
  makeHelp('help', 'Übersicht aller Befehle des Bots (auch als /hilfe)', () => commands),
);

export const byName = new Map(commands.map((c) => [c.data.name, c]));

/** Module mit Schaltflächen, Auswahlmenüs oder Eingabefenstern: handles(customId) und handleComponent(). */
export const componentOwners = [panel, termine, terminNeu, serverregeln, betreiber];
