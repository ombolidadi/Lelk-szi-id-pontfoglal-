// Opcionális e-mail értesítés SMTP-n keresztül. Ha nincs beállítva, csendben kimarad.
const nodemailer = require('nodemailer');

let transport = null;
if (process.env.SMTP_HOST) {
  transport = nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: Number(process.env.SMTP_PORT || 587),
    secure: process.env.SMTP_SECURE === 'true',
    auth: process.env.SMTP_USER ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS } : undefined,
  });
}

async function send(to, subject, text) {
  if (!transport || !to) return;
  try {
    await transport.sendMail({ from: process.env.SMTP_FROM || process.env.SMTP_USER, to, subject, text });
  } catch (err) {
    console.error('[mail] küldési hiba:', err.message);
  }
}

module.exports = { send, enabled: () => Boolean(transport) };
