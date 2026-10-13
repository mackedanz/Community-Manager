// Einrichtungs-Panel von /einrichten: fünf Rollenauswahlen. Jede Änderung speichert die Rollen und gleicht danach
// die Zugangsliste ab (wie DiscordController::component() der PHP-App).
import { guildIconUrl, guildInfo, guildMembers, invokerName, isGuildAdmin } from '../lib/guild.js';
import { OrgError } from '../lib/errors.js';
import { ensureOrg, findOrg, setRoles, syncAllowlist, syncNote } from '../lib/onboarding.js';
import { MAX_ROLES, parseRoleIds, parseRoleLabels, ROLE_KINDS } from '../lib/roles.js';
import { cleanHandleStyle, HANDLE_STYLES } from '../lib/rsi.js';

/** custom_id der Auswahl => Rollenart */
const SELECTS = { 'onb:use': 'use', 'onb:plan': 'plan', 'onb:recruit': 'recruit', 'onb:instruct': 'instruct', 'onb:lead': 'lead' };

/** Nachricht mit den Rollenauswahlen (Discord erlaubt höchstens 5 Zeilen). */
export function panelData(org, note, appUrl) {
  const labels = parseRoleLabels(org.role_labels);
  const ids = (kind) => parseRoleIds(org[ROLE_KINDS[kind]]);
  const names = (list) => (list.length === 0 ? '–' : list.map((id) => labels[id] ?? `<@&${id}>`).join(', '));
  const select = (customId, placeholder, kind) => {
    const s = { type: 6, custom_id: customId, placeholder, min_values: 0, max_values: MAX_ROLES };
    if (ids(kind).length > 0) s.default_values = ids(kind).map((id) => ({ id, type: 'role' }));
    return { type: 1, components: [s] };
  };
  const text = `**Community-Hangar: ${org.name}**\n`
    + `Dürfen das Tool nutzen: ${names(ids('use'))}\n`
    + `Dürfen Events planen: ${names(ids('plan'))}\n`
    + `Recruiter (sehen die RSI-Zugehörigkeit): ${names(ids('recruit'))}\n`
    + `Ausbilder (pflegen die Ausbildung): ${names(ids('instruct'))}\n`
    + `Abteilungsleiter (pflegen die Abteilungen): ${names(ids('lead'))}\n`
    + (org.rsi_sid ? `RSI-Orga: ${org.rsi_org_name} (${org.rsi_sid})\n` : '')
    + `RSI-Handle im Nickname: ${HANDLE_STYLES[cleanHandleStyle(org.handle_style)]}\n`
    + '\n'
    + `Wähle unten die Rollen. Wer eine Nutzungs-, Planer-, Recruiter-, Ausbilder- oder Abteilungsleiter-Rolle hat, darf sich auf ${appUrl} anmelden.`
    + (note != null ? `\n\n${note}` : '');
  return {
    content: text,
    allowedMentions: { parse: [] },
    components: [
      select('onb:use', 'Wer darf das Tool nutzen?', 'use'),
      select('onb:plan', 'Wer darf Events planen? (optional)', 'plan'),
      select('onb:recruit', 'Wer sind die Recruiter? (optional)', 'recruit'),
      select('onb:instruct', 'Wer sind die Ausbilder? (optional)', 'instruct'),
      // Der Abgleich läuft nach jeder Rollenänderung und per /abgleichen
      select('onb:lead', 'Wer sind die Abteilungsleiter? (optional)', 'lead'),
    ],
  };
}

export function handles(customId) {
  return customId in SELECTS || customId === 'onb:sync';
}

/** Rollenauswahl oder (ältere Nachrichten) Schaltfläche "Abgleichen" im Panel. */
export async function handleComponent(interaction, { pool, config }) {
  if (!isGuildAdmin(interaction)) {
    await interaction.reply({ content: 'Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).', flags: 64, allowedMentions: { parse: [] } });
    return;
  }
  await interaction.deferUpdate();
  let org;
  try {
    const guild = await guildInfo(interaction.client, interaction.guildId).catch(() => null);
    org = await ensureOrg(pool, config, {
      guildId: interaction.guildId,
      invokerId: interaction.user.id,
      invokerName: invokerName(interaction),
      guild,
      iconUrl: guild ? guildIconUrl(interaction.guildId, guild.icon) : null,
    });
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    await interaction.followUp({ content: err.message, flags: 64, allowedMentions: { parse: [] } });
    return;
  }

  let sync = true;
  const kind = SELECTS[interaction.customId];
  if (kind) {
    const values = interaction.values.map(String);
    const names = Object.fromEntries([...(interaction.roles?.values() ?? [])].map((r) => [r.id, r.name]));
    try {
      await setRoles(pool, org.id, kind, values, names);
    } catch (err) {
      if (!(err instanceof OrgError)) throw err;
      await interaction.editReply(panelData(org, err.message, config.appUrl));
      return;
    }
    org = (await findOrg(pool, org.id)) ?? org;
    sync = parseRoleIds(org.member_role_ids).length > 0;
  }

  await interaction.editReply(panelData(org, sync ? 'Mitglieder werden abgeglichen …' : null, config.appUrl));
  if (!sync) return;
  let note;
  try {
    note = syncNote(await syncAllowlist(pool, config, org.id, (guildId) => guildMembers(interaction.client, guildId)));
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    note = `Abgleich nicht möglich: ${err.message}`;
  }
  const fresh = await findOrg(pool, org.id);
  if (fresh !== null) await interaction.editReply(panelData(fresh, note, config.appUrl));
}
