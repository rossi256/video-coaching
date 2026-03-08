const nodemailer = require('nodemailer');

function getTransporter() {
  return nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: parseInt(process.env.SMTP_PORT || '587'),
    secure: process.env.SMTP_PORT === '465',
    auth: {
      user: process.env.SMTP_USER,
      pass: process.env.SMTP_PASS,
    },
  });
}

async function sendAdminNotification(name, email) {
  const transporter = getTransporter();
  await transporter.sendMail({
    from: `"WingCoach" <${process.env.SMTP_USER}>`,
    to: process.env.NOTIFY_EMAIL,
    subject: `New WingCoach payment: ${name}`,
    text: `New payment received!\n\nName: ${name}\nEmail: ${email}\n\nLogin to admin to view the submission:\n${process.env.BASE_URL}/admin`,
  });
}

async function sendSubmissionNotification(name, email, submissionId, sub) {
  const transporter = getTransporter();
  const adminUrl = `${process.env.BASE_URL}/admin#submission-${submissionId}`;

  const riderRows = sub ? `
    <tr><td style="color:#64748b;padding:6px 12px 6px 0;white-space:nowrap;font-size:13px;">Level</td><td style="padding:6px 0;font-size:13px;">${sub.level || '—'}</td></tr>
    <tr><td style="color:#64748b;padding:6px 12px 6px 0;white-space:nowrap;font-size:13px;">Location</td><td style="padding:6px 0;font-size:13px;">${sub.location || '—'}</td></tr>
    <tr><td style="color:#64748b;padding:6px 12px 6px 0;white-space:nowrap;font-size:13px;">Equipment</td><td style="padding:6px 0;font-size:13px;">${sub.equipment || '—'}</td></tr>
    <tr><td style="color:#64748b;padding:6px 12px 6px 0;white-space:nowrap;font-size:13px;">Ride Frequency</td><td style="padding:6px 0;font-size:13px;">${sub.ride_frequency || '—'}</td></tr>
  ` : '';

  const coachingRows = sub ? `
    <div style="margin-top:16px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;margin-bottom:4px;">Stuck on</p>
      <p style="font-size:13px;color:#e2e8f0;background:#1e293b;padding:10px;border-radius:6px;">${sub.stuck_on || '—'}</p>
    </div>
    <div style="margin-top:12px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;margin-bottom:4px;">Tried</p>
      <p style="font-size:13px;color:#e2e8f0;background:#1e293b;padding:10px;border-radius:6px;">${sub.tried || '—'}</p>
    </div>
    <div style="margin-top:12px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;margin-bottom:4px;">Success looks like</p>
      <p style="font-size:13px;color:#e2e8f0;background:#1e293b;padding:10px;border-radius:6px;">${sub.success_looks_like || '—'}</p>
    </div>
  ` : '';

  await transporter.sendMail({
    from: `"WingCoach" <${process.env.SMTP_USER}>`,
    to: process.env.NOTIFY_EMAIL,
    subject: `New coaching submission from ${name}`,
    html: `
      <div style="font-family:Inter,Arial,sans-serif;max-width:600px;margin:0 auto;background:#0d1b2e;color:#e2e8f0;padding:32px;border-radius:12px;">
        <h2 style="color:#0ea5e9;margin:0 0 4px;">New coaching submission</h2>
        <p style="margin:0 0 20px;color:#94a3b8;font-size:14px;">Submission #${submissionId}</p>
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
          <tr><td style="color:#64748b;padding:6px 12px 6px 0;white-space:nowrap;font-size:13px;">Name</td><td style="padding:6px 0;font-size:13px;font-weight:600;">${name}</td></tr>
          <tr><td style="color:#64748b;padding:6px 12px 6px 0;white-space:nowrap;font-size:13px;">Email</td><td style="padding:6px 0;font-size:13px;"><a href="mailto:${email}" style="color:#0ea5e9;">${email}</a></td></tr>
          ${riderRows}
        </table>
        ${coachingRows}
        <div style="margin-top:24px;text-align:center;">
          <a href="${adminUrl}" style="display:inline-block;background:#0ea5e9;color:white;font-weight:700;padding:12px 28px;border-radius:8px;text-decoration:none;font-size:14px;">
            View in Admin →
          </a>
        </div>
      </div>
    `,
  });
}

async function sendFeedbackReady(email, name, replyUrl) {
  const transporter = getTransporter();
  await transporter.sendMail({
    from: `"Michi @ WingCoach" <${process.env.SMTP_USER}>`,
    to: email,
    subject: `Your coaching feedback from Michi is ready`,
    html: `
      <div style="font-family: Inter, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #1e293b;">
        <div style="background: #0d1b2e; padding: 32px; text-align: center; border-radius: 8px 8px 0 0;">
          <h1 style="color: #0ea5e9; margin: 0; font-size: 22px;">WingCoach by Tricktionary</h1>
        </div>
        <div style="background: #f8fafc; padding: 32px; border-radius: 0 0 8px 8px;">
          <h2 style="color: #0d1b2e;">Hey ${name} — your feedback is ready.</h2>
          <p>Michi has reviewed your videos and recorded a personal coaching response just for you.</p>
          <p style="text-align: center; margin: 32px 0;">
            <a href="${replyUrl}" style="display: inline-block; background: #0ea5e9; color: white; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px;">
              Watch Your Coaching Feedback
            </a>
          </p>
          <p style="color: #64748b; font-size: 14px;">Or copy this link: <a href="${replyUrl}" style="color: #0ea5e9;">${replyUrl}</a></p>
          <p>This link is yours — you can come back to it anytime.</p>
          <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
          <p>Got questions?</p>
          <p>
            WhatsApp Michi: <a href="https://wa.me/4369913909040" style="color: #0ea5e9;">+43 699 139 09040</a><br>
            Email: <a href="mailto:info@tricktionary.com" style="color: #0ea5e9;">info@tricktionary.com</a>
          </p>
          <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
          <p style="color: #94a3b8; font-size: 12px; text-align: center;">
            WingCoach by Tricktionary &mdash;
            <a href="https://tricktionary.com" style="color: #94a3b8;">tricktionary.com</a>
          </p>
        </div>
      </div>
    `,
  });
}

async function sendUploadLink(email, name, uploadUrl) {
  const transporter = getTransporter();
  await transporter.sendMail({
    from: `"WingCoach" <${process.env.SMTP_USER}>`,
    to: email,
    subject: 'Your WingCoach upload link — come back anytime',
    html: `
      <div style="font-family:Inter,sans-serif;max-width:540px;margin:0 auto;background:#0d1b2e;color:#e2e8f0;padding:40px 32px;border-radius:16px">
        <div style="margin-bottom:28px">
          <span style="font-weight:900;color:#0ea5e9;font-size:20px">WING</span><span style="font-weight:300;font-size:20px">COACH</span>
          <span style="color:#475569;font-size:14px;margin-left:8px">by Tricktionary</span>
        </div>
        <h2 style="font-size:22px;font-weight:700;margin-bottom:8px">Hey ${name || 'there'} 👋</h2>
        <p style="color:#94a3b8;line-height:1.6;margin-bottom:24px">
          Your coaching spot is secured. Use the link below to upload your riding videos and fill out your rider profile — you can come back to it any time, your progress is saved automatically.
        </p>
        <a href="${uploadUrl}" style="display:inline-block;background:#0ea5e9;color:white;font-weight:700;padding:14px 28px;border-radius:10px;text-decoration:none;font-size:15px;margin-bottom:28px">
          → Go to my upload page
        </a>
        <p style="color:#475569;font-size:13px;line-height:1.5">
          Bookmark this email or save the link — it's your personal access to this coaching session.<br><br>
          Questions? <a href="https://wa.me/4369913909040" style="color:#0ea5e9;">WhatsApp Michi</a> or <a href="mailto:info@tricktionary.com" style="color:#0ea5e9;">info@tricktionary.com</a>
        </p>
      </div>
    `,
  });
}

async function sendAbandonedCheckoutReminder(email, checkoutUrl) {
  const transporter = getTransporter();
  await transporter.sendMail({
    from: `"WingCoach" <${process.env.SMTP_USER}>`,
    to: email,
    subject: 'Your coaching spot is still waiting',
    html: `
      <div style="font-family:Inter,sans-serif;max-width:540px;margin:0 auto;background:#0d1b2e;color:#e2e8f0;padding:40px 32px;border-radius:16px">
        <div style="margin-bottom:28px">
          <span style="font-weight:900;color:#0ea5e9;font-size:20px">WING</span><span style="font-weight:300;font-size:20px">COACH</span>
          <span style="color:#94a3b8;font-size:14px;margin-left:8px">by Tricktionary</span>
        </div>
        <h2 style="font-size:22px;font-weight:700;margin-bottom:8px">Hey — you were this close.</h2>
        <p style="color:#cbd5e1;line-height:1.6;margin-bottom:16px">
          Your founding coaching spot with Michi is still available. Once all 10 spots fill up, the price goes to €149.
        </p>
        <p style="color:#e2e8f0;line-height:1.6;margin-bottom:28px">
          Click below to come back and lock it in.
        </p>
        <a href="${checkoutUrl}" style="display:inline-block;background:#0ea5e9;color:white;font-weight:700;padding:14px 28px;border-radius:10px;text-decoration:none;font-size:15px;margin-bottom:28px">
          → Claim my founding spot
        </a>
        <p style="color:#94a3b8;font-size:13px;line-height:1.5">
          Questions? <a href="https://wa.me/4369913909040" style="color:#0ea5e9;">WhatsApp Michi</a> — happy to answer before you buy.
        </p>
      </div>
    `,
  });
}

async function sendSubmissionConfirmation(email, name, uploadUrl) {
  const transporter = getTransporter();
  await transporter.sendMail({
    from: `"Michi @ WingCoach" <${process.env.SMTP_USER}>`,
    to: email,
    subject: 'Got it — Michi is on it 🎯',
    html: `
      <div style="font-family:Inter,sans-serif;max-width:540px;margin:0 auto;background:#0d1b2e;color:#e2e8f0;padding:40px 32px;border-radius:16px">
        <div style="margin-bottom:28px">
          <span style="font-weight:900;color:#0ea5e9;font-size:20px">WING</span><span style="font-weight:300;font-size:20px">COACH</span>
          <span style="color:#475569;font-size:14px;margin-left:8px">by Tricktionary</span>
        </div>
        <h2 style="font-size:22px;font-weight:700;margin-bottom:8px">Hey ${name || 'there'} — got it! 🎯</h2>
        <p style="color:#94a3b8;line-height:1.6;margin-bottom:16px">
          Your videos and profile are in. I'll review everything and send your personalized coaching video within 72 hours.
        </p>
        <p style="color:#e2e8f0;font-weight:600;margin-bottom:24px">
          Watch your inbox.
        </p>
        <a href="${uploadUrl}" style="display:inline-block;background:rgba(14,165,233,0.15);border:1px solid rgba(14,165,233,0.4);color:#38bdf8;font-weight:600;padding:12px 24px;border-radius:10px;text-decoration:none;font-size:14px;margin-bottom:28px">
          Come back to your submission →
        </a>
        <p style="color:#475569;font-size:13px;line-height:1.5">
          Questions while you wait? <a href="https://wa.me/4369913909040" style="color:#0ea5e9;">WhatsApp Michi</a>
        </p>
      </div>
    `,
  });
}

async function sendReceiptConfirmation(email, name) {
  const transporter = getTransporter();
  await transporter.sendMail({
    from: `"Michi @ WingCoach" <${process.env.SMTP_USER}>`,
    to: email,
    subject: 'Michi just confirmed your submission is in 👋',
    html: `
      <div style="font-family:Inter,sans-serif;max-width:540px;margin:0 auto;background:#0d1b2e;color:#e2e8f0;padding:40px 32px;border-radius:16px">
        <div style="margin-bottom:28px">
          <span style="font-weight:900;color:#0ea5e9;font-size:20px">WING</span><span style="font-weight:300;font-size:20px">COACH</span>
          <span style="color:#475569;font-size:14px;margin-left:8px">by Tricktionary</span>
        </div>
        <h2 style="font-size:22px;font-weight:700;margin-bottom:8px">Hey ${name || 'there'} 👋</h2>
        <p style="color:#94a3b8;line-height:1.6;margin-bottom:16px">
          Just to let you know — Michi confirmed the receipt of your submission and started working on it.
        </p>
        <p style="color:#e2e8f0;line-height:1.6;margin-bottom:28px">
          Stay tuned for your coaching video — you'll get an email as soon as it's ready.
        </p>
        <p style="color:#475569;font-size:13px;line-height:1.5">
          Got a quick question while you wait? <a href="https://wa.me/4369913909040" style="color:#0ea5e9;">WhatsApp Michi</a>
        </p>
      </div>
    `,
  });
}

module.exports = {
  sendAdminNotification,
  sendSubmissionNotification,
  sendFeedbackReady,
  sendUploadLink,
  sendAbandonedCheckoutReminder,
  sendSubmissionConfirmation,
  sendReceiptConfirmation,
};
