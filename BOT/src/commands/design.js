// /design: Logo, Hintergrund und Deckkraft der Installation. Nur Server-Admins eines eingerichteten Servers
// (wie DiscordController::designCommand() der PHP-App).
import { MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import { current, fetchImage, reset, setImage, setOpacity } from '../lib/branding.js';
import { OrgError } from '../lib/errors.js';
import { isGuildAdmin } from '../lib/guild.js';

export const data = {
  name: 'design',
  description: 'Aussehen der App: Logo, Hintergrundbild und Deckkraft (nur Server-Admins)',
  type: 1,
  options: [
    { type: 11, name: 'logo', description: 'Neues Logo (PNG, JPG oder WebP)', required: false },
    { type: 11, name: 'hintergrund', description: 'Neues Hintergrundbild (PNG, JPG oder WebP)', required: false },
    { type: 4, name: 'deckkraft_dunkel', description: 'Deckkraft des Hintergrunds im dunklen Modus in Prozent (Standard 10)', required: false, min_value: 0, max_value: 100 },
    { type: 4, name: 'deckkraft_hell', description: 'Deckkraft des Hintergrunds im hellen Modus in Prozent (Standard 40)', required: false, min_value: 0, max_value: 100 },
    { type: 3, name: 'zuruecksetzen', description: 'Auf die Standardwerte zurücksetzen', required: false, choices: [
      { name: 'Logo', value: 'logo' }, { name: 'Hintergrund', value: 'background' }, { name: 'Alles', value: 'all' },
    ] },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

const RESET_NAMES = { logo: 'Logo', background: 'Hintergrund', all: 'alles' };

export async function execute(interaction, { pool, config }) {
  const reply = (content) => interaction.reply({ content, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) {
    await reply('Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).');
    return;
  }
  if ((await orgForGuild(pool, interaction.guildId)) === null) {
    await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.');
    return;
  }
  const o = interaction.options;
  const logo = o.getAttachment('logo')?.url ?? null;
  const background = o.getAttachment('hintergrund')?.url ?? null;
  const dark = o.getInteger('deckkraft_dunkel');
  const light = o.getInteger('deckkraft_hell');
  const what = o.getString('zuruecksetzen');

  if (logo === null && background === null && dark === null && light === null && what === null) {
    const b = await current(pool, config.imageDir);
    await reply(
      `Aktuell: Logo ${b.logoCustom ? 'eigenes' : 'Standard'}, Hintergrund ${b.backgroundCustom ? 'eigener' : 'Standard'}`
      + `, Deckkraft dunkel ${b.opacityDark} %, hell ${b.opacityLight} %.\n`
      + 'Mit /design kannst du ein Logo, ein Hintergrundbild und die Deckkraft (getrennt für dunkel und hell) setzen oder auf Standard zurücksetzen.',
    );
    return;
  }

  // Laden und Umrechnen der Bilder kann länger dauern als die 3 Sekunden, die Discord für die Antwort lässt
  await interaction.deferReply({ flags: MessageFlags.Ephemeral });
  const done = [];
  let note;
  try {
    if (what !== null) {
      await reset(pool, config.imageDir, what);
      done.push(`zurückgesetzt (${RESET_NAMES[what]})`);
    }
    if (logo !== null) {
      await setImage(pool, config.imageDir, 'logo', await fetchImage(logo));
      done.push('Logo gesetzt');
    }
    if (background !== null) {
      await setImage(pool, config.imageDir, 'background', await fetchImage(background));
      done.push('Hintergrund gesetzt');
    }
    if (dark !== null || light !== null) {
      await setOpacity(pool, dark, light);
      done.push(`Deckkraft ${[dark !== null ? `dunkel ${dark} %` : null, light !== null ? `hell ${light} %` : null].filter(Boolean).join(', ')}`);
    }
    note = `Fertig: ${done.join('; ')}. Im Browser hilft ggf. Strg+F5.`;
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    note = `${done.length > 0 ? `Teilweise erledigt (${done.join('; ')}). ` : ''}Nicht möglich: ${err.message}`;
  }
  await interaction.editReply({ content: note, allowedMentions: { parse: [] } });
}
