const express = require('express');
const router = express.Router();
const {
  createEvent, updateEvent, getEvent, getAllEvents, deleteEvent,
  addEventTodo, getEventTodos, updateEventTodo, deleteEventTodo,
} = require('../db');

// ── Events CRUD ──────────────────────────────────────────────────────────────

router.get('/events', (req, res) => {
  res.json(getAllEvents());
});

router.get('/events/:id', (req, res) => {
  const event = getEvent(parseInt(req.params.id));
  if (!event) return res.status(404).json({ error: 'Event not found' });
  const todos = getEventTodos(event.id);
  res.json({ ...event, todos });
});

router.post('/events', (req, res) => {
  const allowedFields = [
    'name', 'location', 'start_date', 'end_date', 'event_type',
    'attendees_min', 'attendees_base', 'attendees_max', 'price_per_person',
    'accom_per_person_night', 'accom_nights', 'rental_per_person',
    'coaching_rate_per_day', 'coaching_days', 'commission_pct',
    'extras_per_person', 'extras_description', 'marketing_pct',
    'org_hours', 'org_hourly_rate', 'admin_pct', 'notes',
  ];
  const data = {};
  for (const key of allowedFields) {
    if (req.body[key] !== undefined && req.body[key] !== '') data[key] = req.body[key];
  }
  if (!data.name) return res.status(400).json({ error: 'Event name is required' });
  const id = createEvent(data);
  res.json({ id, ...data });
});

router.put('/events/:id', (req, res) => {
  const id = parseInt(req.params.id);
  const event = getEvent(id);
  if (!event) return res.status(404).json({ error: 'Event not found' });
  const allowedFields = [
    'name', 'location', 'start_date', 'end_date', 'event_type',
    'attendees_min', 'attendees_base', 'attendees_max', 'price_per_person',
    'accom_per_person_night', 'accom_nights', 'rental_per_person',
    'coaching_rate_per_day', 'coaching_days', 'commission_pct',
    'extras_per_person', 'extras_description', 'marketing_pct',
    'org_hours', 'org_hourly_rate', 'admin_pct', 'notes',
  ];
  const data = {};
  for (const key of allowedFields) {
    if (req.body[key] !== undefined) data[key] = req.body[key];
  }
  updateEvent(id, data);
  res.json({ ok: true });
});

router.delete('/events/:id', (req, res) => {
  const id = parseInt(req.params.id);
  const event = getEvent(id);
  if (!event) return res.status(404).json({ error: 'Event not found' });
  deleteEvent(id);
  res.json({ ok: true });
});

// ── Event Todos ──────────────────────────────────────────────────────────────

router.post('/events/:id/todos', (req, res) => {
  const eventId = parseInt(req.params.id);
  const event = getEvent(eventId);
  if (!event) return res.status(404).json({ error: 'Event not found' });
  const { phase, title, responsible, due_date, notes } = req.body;
  if (!title) return res.status(400).json({ error: 'Title is required' });
  const id = addEventTodo(eventId, { phase: phase || 1, title, responsible, due_date, notes });
  res.json({ id });
});

router.patch('/events/todos/:todoId', (req, res) => {
  const { done, title, responsible, due_date, notes, phase } = req.body;
  const fields = {};
  if (done !== undefined) fields.done = done ? 1 : 0;
  if (title !== undefined) fields.title = title;
  if (responsible !== undefined) fields.responsible = responsible;
  if (due_date !== undefined) fields.due_date = due_date;
  if (notes !== undefined) fields.notes = notes;
  if (phase !== undefined) fields.phase = phase;
  updateEventTodo(parseInt(req.params.todoId), fields);
  res.json({ ok: true });
});

router.delete('/events/todos/:todoId', (req, res) => {
  deleteEventTodo(parseInt(req.params.todoId));
  res.json({ ok: true });
});

// ── Seed default todos for a new event ───────────────────────────────────────

router.post('/events/:id/seed-todos', (req, res) => {
  const eventId = parseInt(req.params.id);
  const event = getEvent(eventId);
  if (!event) return res.status(404).json({ error: 'Event not found' });

  const defaults = [
    { phase: 1, title: 'Go / No-Go decision', responsible: 'me' },
    { phase: 1, title: 'Set price per person', responsible: 'me' },
    { phase: 1, title: 'Set minimum participants', responsible: 'me' },
    { phase: 1, title: 'Find & confirm venue', responsible: 'me' },
    { phase: 1, title: 'Define event dates', responsible: 'me' },
    { phase: 2, title: 'Create promo content (photos/video)', responsible: 'me' },
    { phase: 2, title: 'Send email to mailing list', responsible: 'me' },
    { phase: 2, title: 'Social media posts (IG, FB)', responsible: 'me' },
    { phase: 2, title: 'Create landing page / booking link', responsible: 'ai' },
    { phase: 2, title: 'Partner outreach / cross-promo', responsible: 'me' },
    { phase: 3, title: 'Book accommodation', responsible: 'me' },
    { phase: 3, title: 'Arrange equipment rental', responsible: 'me' },
    { phase: 3, title: 'Organize transfers / transport', responsible: 'me' },
    { phase: 3, title: 'Confirm catering / meals', responsible: 'me' },
    { phase: 3, title: 'Insurance & liability check', responsible: 'me' },
    { phase: 4, title: 'Welcome & intro session', responsible: 'me' },
    { phase: 4, title: 'Daily coaching schedule', responsible: 'me' },
    { phase: 4, title: 'Photo / video documentation', responsible: 'collaborator' },
    { phase: 4, title: 'Group dinner / social activity', responsible: 'me' },
    { phase: 5, title: 'Send follow-up & thank-you email', responsible: 'ai' },
    { phase: 5, title: 'Collect testimonials', responsible: 'me' },
    { phase: 5, title: 'Post highlights on social media', responsible: 'me' },
    { phase: 5, title: 'Announce next event / early bird', responsible: 'me' },
    { phase: 5, title: 'Financial reconciliation', responsible: 'me' },
  ];

  for (const todo of defaults) {
    addEventTodo(eventId, todo);
  }
  res.json({ ok: true, count: defaults.length });
});

module.exports = router;
