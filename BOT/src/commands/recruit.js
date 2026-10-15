// /recruit: Ein Recruiter geht den Fragenkatalog der Orga durch; die Antworten gehen danach als Übersicht in einen Kanal.
// Fragen, Kanal und die berechtigten Rollen stellt ein Admin im Community-Manager ein (Discord-Bot, Recruiting).
//
// Ablauf: /recruit -> Nachricht mit „Teil 1 starten“ -> Eingabefenster mit bis zu 5 Fragen -> „Weiter“ -> … -> Übersicht im Kanal.
// Discord erlaubt höchstens 5 Felder je Fenster und kein Fenster als Antwort auf ein Fenster; deshalb ein Knopf zwischen den Teilen.
// Die Antworten liegen zwischen den Schritten in der Tabelle bot_recruit_drafts.
import { DiscordAPIError, MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import { isGuildAdmin, memberRoleIds } from '../lib/guild.js';
import {
  answered, batchCount, canRecruit, deleteDraft, getDraft, loadQuestions, modalFor, RECRUIT_CHANNEL, recruitRoleIds, saveAnswers, startDraft,
  summaryMessages, PER_MODAL, purgeDrafts,
} from '../lib/recruit.js';
import { getSetting, isMissingTable, MISSING_TABLE_TEXT } from '../lib/settings.js';

/** Für /hilfe */
export const audience = 'recruiters';

export const data = {
  name: 'recruit',
  description: 'Fragenkatalog für ein Bewerbungsgespräch ausfüllen; die Antworten gehen als Übersicht in einen Kanal',
  type: 1,
  options: [{ type: 6, name: 'bewerber', description: 'Wer bewirbt sich? (freiwillig)', required: false }],
  contexts: [0],
};

const EPHEMERAL = MessageFlags.Ephemeral;
const NO_MENTIONS = { parse: [] };
const HEX_ID = /^[a-f0-9]{24}$/;
const button = (label, customId, style = 1) => ({ type: 2, style, label, custom_id: customId });
const row = (...buttons) => ({ type: 1, components: buttons });

export function handles(customId) {
  return customId.startsWith('recruit:');
}

export async function execute(interaction, { pool }) {
  const reply = (content, extra = {}) => interaction.editReply({ content, embeds: [], components: [], allowedMentions: NO_MENTIONS, ...extra });
  await interaction.deferReply({ flags: EPHEMERAL });
  try {
    const org = await orgForGuild(pool, interaction.guildId);
    if (org === null) return void (await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.'));
    const admin = isGuildAdmin(interaction);
    if (!canRecruit({ admin, roles: memberRoleIds(interaction) }, await recruitRoleIds(pool, org.id))) {
      return void (await reply('Das dürfen nur Recruiter und Server-Admins. Die Rollen legt ein Admin im Community-Manager fest (Discord-Bot, Recruiting).'));
    }
    const channelId = await getSetting(pool, org.id, RECRUIT_CHANNEL);
    if (channelId === null) return void (await reply('Es ist noch kein Kanal für die Übersichten eingestellt. Das macht ein Admin im Community-Manager (Discord-Bot, Recruiting).'));
    const questions = await loadQuestions(pool, org.id);
    if (questions.length === 0) return void (await reply('Es gibt noch keine Fragen. Die legt ein Admin im Community-Manager an (Discord-Bot, Recruiting).'));

    await purgeDrafts(pool);
    const applicant = interaction.options.getUser('bewerber');
    const draftId = await startDraft(pool, {
      orgId: org.id,
      recruiterId: interaction.user.id,
      applicantId: applicant?.id ?? null,
      applicantName: applicant ? (applicant.globalName || applicant.username) : null,
      questions,
    });
    const parts = batchCount(questions);
    await reply(
      `Fragenkatalog${applicant ? ` für ${applicant}` : ''}: ${questions.length} Frage${questions.length === 1 ? '' : 'n'} in ${parts} Teil${parts === 1 ? '' : 'en'}. `
      + `Mit dem Knopf öffnet sich Teil 1. Danach geht es mit „Weiter“ voran. Am Ende landet die Übersicht in <#${channelId}>. `
      + 'Ein früher angefangener, nicht abgeschlossener Katalog von dir ist damit ersetzt.',
      { components: [row(button('Teil 1 starten', `recruit:go:${draftId}:0`, 3), button('Abbrechen', `recruit:cancel:${draftId}`, 2))] },
    );
  } catch (err) {
    if (!isMissingTable(err)) throw err;
    await reply(MISSING_TABLE_TEXT);
  }
}

/** Schaltfläche („Teil starten“, „Weiter“, „Abbrechen“, „Erneut senden“) oder abgeschicktes Eingabefenster. */
export async function handleComponent(interaction, { pool }) {
  const [, kind, draftId, batchText] = interaction.customId.split(':');
  if (!HEX_ID.test(draftId ?? '')) return;
  const gone = (text = 'Dieser Fragenkatalog ist abgelaufen oder erledigt. Starte mit /recruit neu.') => (
    interaction.isFromMessage?.() ? interaction.update({ content: text, embeds: [], components: [] }) : interaction.reply({ content: text, flags: EPHEMERAL })
  );
  try {
    const org = await orgForGuild(pool, interaction.guildId);
    const draft = org === null ? null : await getDraft(pool, org.id, draftId);
    if (draft === null) return void (await gone());
    if (draft.recruiter_id !== interaction.user.id) {
      return void (await interaction.reply({ content: 'Das ist der Fragenkatalog eines anderen Recruiters.', flags: EPHEMERAL }));
    }
    const batch = Math.max(0, Number.parseInt(batchText ?? '0', 10) || 0);
    if (kind === 'cancel') {
      await deleteDraft(pool, draft.id);
      return void (await interaction.update({ content: 'Abgebrochen. Es wurde nichts gepostet.', embeds: [], components: [] }));
    }
    if (kind === 'go') {
      return void (await interaction.showModal(modalFor(draft.id, draft.questions, draft.answers, Math.min(batch, batchCount(draft.questions) - 1))));
    }
    if (kind === 'send') return void (await finish(interaction, pool, org, draft));
    if (kind !== 'modal') return;

    // Eingabefenster abgeschickt: Antworten dieses Teils merken
    const given = {};
    for (let i = batch * PER_MODAL; i < Math.min(draft.questions.length, (batch + 1) * PER_MODAL); i++) {
      given[i] = interaction.fields.getTextInputValue(`q${i}`) ?? '';
    }
    draft.answers = await saveAnswers(pool, draft, given);
    const parts = batchCount(draft.questions);
    if (batch < parts - 1) {
      const done = answered(draft.questions, draft.answers);
      const payload = {
        content: `Teil ${batch + 1} von ${parts} ist gespeichert (${done} von ${draft.questions.length} Fragen beantwortet).`,
        embeds: [],
        components: [row(button(`Weiter mit Teil ${batch + 2}`, `recruit:go:${draft.id}:${batch + 1}`, 3), button('Abbrechen', `recruit:cancel:${draft.id}`, 2))],
      };
      return void (await (interaction.isFromMessage?.() ? interaction.update(payload) : interaction.reply({ ...payload, flags: EPHEMERAL })));
    }
    await finish(interaction, pool, org, draft);
  } catch (err) {
    if (isMissingTable(err)) return void (await interaction.reply({ content: MISSING_TABLE_TEXT, flags: EPHEMERAL }));
    throw err;
  }
}

/** Übersicht in den Kanal posten und den Entwurf löschen; klappt das Posten nicht, bleibt der Entwurf und es gibt „Erneut senden“. */
async function finish(interaction, pool, org, draft) {
  const channelId = await getSetting(pool, org.id, RECRUIT_CHANNEL);
  const show = (payload) => (interaction.isFromMessage?.() ? interaction.update(payload) : interaction.reply({ ...payload, flags: EPHEMERAL }));
  const retry = row(button('Erneut senden', `recruit:send:${draft.id}`, 1), button('Abbrechen', `recruit:cancel:${draft.id}`, 2));
  if (channelId === null) {
    return void (await show({ content: 'Es ist kein Kanal für die Übersichten mehr eingestellt. Die Antworten sind gespeichert; ein Admin stellt den Kanal im Community-Manager ein, dann „Erneut senden“.', embeds: [], components: [retry] }));
  }
  try {
    const channel = await interaction.client.channels.fetch(channelId);
    let first = null;
    for (const message of summaryMessages(draft)) {
      const sent = await channel.send(message);
      first ??= sent;
    }
    await deleteDraft(pool, draft.id);
    await show({ content: `Fertig. Die Übersicht steht in <#${channelId}>: ${first?.url ?? ''}`, embeds: [], components: [] });
  } catch (err) {
    if (!(err instanceof DiscordAPIError)) throw err;
    await show({
      content: `Die Übersicht konnte nicht gepostet werden (${err.message}). Der Bot braucht im Kanal <#${channelId}> die Rechte „Kanal ansehen“, „Nachrichten senden“ und „Links einbetten“. Deine Antworten sind gespeichert.`,
      embeds: [],
      components: [retry],
    });
  }
}
