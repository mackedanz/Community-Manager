// Einfache Log-Ausgabe mit Zeitstempel (UTC); im Container landet sie in "docker compose logs bot".
function line(level, message, err) {
  const text = `${new Date().toISOString()} ${level} ${message}`;
  if (level === 'INFO') console.log(text);
  else console.error(err ? `${text}\n${err.stack ?? err}` : text);
}

export const log = {
  info: (message) => line('INFO', message),
  warn: (message, err) => line('WARN', message, err),
  error: (message, err) => line('ERROR', message, err),
};
