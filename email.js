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

async function sendSubmissionNotification(name, email, submissionId) {
  const transporter = getTransporter();
  await transporter.sendMail({
    from: `"WingCoach" <${process.env.SMTP_USER}>`,
    to: process.env.NOTIFY_EMAIL,
    subject: `New coaching submission from ${name}`,
    text: `A rider has submitted their videos and profile.\n\nName: ${name}\nEmail: ${email}\nSubmission ID: ${submissionId}\n\nReview at: ${process.env.BASE_URL}/admin`,
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

module.exports = { sendAdminNotification, sendSubmissionNotification, sendFeedbackReady, sendUploadLink };
