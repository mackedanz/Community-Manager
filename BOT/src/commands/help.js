// /help und /hilfe: Übersicht aller Befehle, erzeugt aus der Befehlsliste (neue Befehle erscheinen von selbst).
// Gruppiert nach "Für alle", "Für Mitglieder der Orga" und "Für Server-Admins"; Admin-Befehle sieht nur, wer sie nutzen darf.
import { MessageFlags } from 'discord.js';
import { isGuildAdmin } from '../lib/guild.js';

const GROUPS = [
  ['everyone', 'Für alle'],
  ['members', 'Für Mitglieder der Orga'],
  ['planners', 'Für Planer und Admins'],
  ['admins', 'Für Server-Admins'],
  ['operator', 'Für den Betreiber der Installation'],
];

/** Wer darf den Befehl nutzen? Aus help.audience, sonst aus den Standardrechten des Befehls. */
export function audienceOf(command) {
  return command.audience ?? (command.data.default_member_permissions ? 'admins' : 'everyone');
}

/** "/termine-kanal [kanal] [aus]": Pflichtangaben in <>, freiwillige in []; Unterbefehle mit | getrennt. */
export function usage(data) {
  const subs = (data.options ?? []).filter((o) => o.type === 1);
  if (subs.length > 0) return `/${data.name} ${subs.map((s) => s.name).join('|')}`;
  const opts = (data.options ?? []).map((o) => (o.required ? `<${o.name}>` : `[${o.name}]`));
  return [`/${data.name}`, ...opts].join(' ');
}

/** Text der Übersicht. ids: Befehlsname => ID bei Discord (dann sind die Befehle anklickbar). */
export function helpText(commands, { admin, operator = false, ids = new Map(), appUrl }) {
  const seen = new Set();
  const sections = [];
  for (const [group, title] of GROUPS) {
    if (group === 'admins' && !admin) continue;
    if (group === 'operator' && !operator) continue;
    const lines = commands
      .filter((c) => audienceOf(c) === group && !c.hideInHelp && !seen.has(c.data.name))
      .map((c) => {
        seen.add(c.data.name);
        const id = ids.get(c.data.name);
        const name = id ? `</${c.data.name}:${id}>` : `\`/${c.data.name}\``;
        const extra = c.data.options?.length ? ` · \`${usage(c.data)}\`` : '';
        return `${name} – ${c.data.description}${extra}`;
      });
    if (lines.length > 0) sections.push(`**${title}**\n${lines.join('\n')}`);
  }
  const foot = admin ? '' : '\n\nWeitere Befehle für Server-Admins zeigt /hilfe, wenn ein Admin es aufruft.';
  return `${sections.join('\n\n')}\n\n`
    + `<…> muss angegeben werden, […] ist freiwillig. Alles Weitere auf ${appUrl}${foot}`;
}

/** Befehls-IDs bei Discord, damit die Übersicht anklickbare Befehle zeigt; ohne Verbindung einfach leer. */
let idCache = null;
async function commandIds(client) {
  if (idCache !== null) return idCache;
  try {
    const list = await client.application.commands.fetch();
    idCache = new Map([...list.values()].map((c) => [c.name, c.id]));
    return idCache;
  } catch {
    return new Map();
  }
}

/** Baut einen Hilfe-Befehl; getCommands liefert die aktuelle Befehlsliste (vermeidet einen Import im Kreis). */
export function makeHelp(name, description, getCommands) {
  return {
    data: { name, description, type: 1, contexts: [0] },
    audience: 'everyone',
    // /help und /hilfe stehen als eine Zeile in der Übersicht
    hideInHelp: name === 'help',
    async execute(interaction, { config }) {
      const text = helpText(getCommands(), {
        admin: isGuildAdmin(interaction),
        operator: config.serverAdminIds.includes(interaction.user.id),
        ids: await commandIds(interaction.client),
        appUrl: config.appUrl,
      });
      // Als Kasten: dort sind bis zu 4096 Zeichen erlaubt, im normalen Text nur 2000
      await interaction.reply({ embeds: [{ title: 'Befehle des Community-Managers', description: text.slice(0, 4096), color: 0x0ea5e9 }], flags: MessageFlags.Ephemeral });
    },
  };
}
