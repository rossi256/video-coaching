/* WingCoach player - our own controls on top of video.js, used by the reply page.
 *
 *  - ±10 s buttons in the control bar (video.js skipButtons, set in initPlayer)
 *  - touch: double-tap the left or right third to skip 10 s, keep tapping to add more
 *  - keyboard: Space/K play-pause, ←/J and →/L 10 s, , and . one frame (paused),
 *    F fullscreen, M mute, 0-9 jump to 0-90 %
 *  - Vimeo's signed stream links expire after about a day: if one dies on a page left
 *    open, fetch fresh ones and carry on from the same second.
 */
(function () {
  'use strict';
  var SKIP = 10;
  var FRAME = 1 / 30;
  var lastActive = null;

  function seekBy(player, delta) {
    var d = player.duration();
    var t = (player.currentTime() || 0) + delta;
    if (isFinite(d) && d > 0) t = Math.min(t, d - 0.05);
    player.currentTime(Math.max(0, t));
  }

  function flash(player, side, text) {
    var root = player.el();
    var el = root.querySelector('.wc-skip-flash.' + side);
    if (!el) {
      el = document.createElement('div');
      el.className = 'wc-skip-flash ' + side;
      el.setAttribute('aria-hidden', 'true');
      root.appendChild(el);
    }
    el.textContent = text;
    el.classList.remove('show');
    void el.offsetWidth; // restart the animation
    el.classList.add('show');
  }

  function togglePlay(player) {
    if (player.paused()) {
      var p = player.play();
      if (p && p.catch) p.catch(function () {});
    } else {
      player.pause();
    }
  }

  // Shared by the player's own hotkeys and the page-level listener below.
  function handleKey(player, e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return false;
    var k = e.key;
    switch (k) {
      case ' ': case 'k': case 'K': togglePlay(player); break;
      case 'ArrowLeft': case 'j': case 'J': seekBy(player, -SKIP); flash(player, 'left', '−' + SKIP + ' s'); break;
      case 'ArrowRight': case 'l': case 'L': seekBy(player, SKIP); flash(player, 'right', '+' + SKIP + ' s'); break;
      case ',': if (player.paused()) seekBy(player, -FRAME); else return false; break;
      case '.': if (player.paused()) seekBy(player, FRAME); else return false; break;
      case 'f': case 'F':
        if (player.isFullscreen()) player.exitFullscreen(); else player.requestFullscreen();
        break;
      case 'm': case 'M': player.muted(!player.muted()); break;
      default:
        if (k.length === 1 && k >= '0' && k <= '9') {
          var d = player.duration();
          if (isFinite(d) && d > 0) player.currentTime(d * Number(k) / 10);
          break;
        }
        return false;
    }
    e.preventDefault();
    return true;
  }

  // Touch double-tap: left/right third skips; YouTube-style, each further tap within
  // the window adds another 10 s. A single tap keeps video.js's own behaviour
  // (show/hide the controls).
  function bindDoubleTap(player) {
    var root = player.el();
    var lastTap = 0, lastSide = null, streak = 0, streakTimer = null;
    root.addEventListener('pointerup', function (e) {
      if (e.pointerType !== 'touch') return;
      if (e.target.closest('.vjs-control-bar, .vjs-menu, .vjs-big-play-button, .vjs-mirror-button, .vjs-modal-dialog, .vjs-text-track-settings')) return;
      var r = root.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width;
      var side = x < 0.35 ? 'left' : (x > 0.65 ? 'right' : null);
      var now = Date.now();
      var inStreak = side && side === lastSide && (now - lastTap < (streak ? 700 : 320));
      lastTap = now;
      lastSide = side;
      if (!inStreak) { streak = 0; return; }
      streak += 1;
      seekBy(player, side === 'left' ? -SKIP : SKIP);
      flash(player, side, (side === 'left' ? '−' : '+') + (streak * SKIP) + ' s');
      clearTimeout(streakTimer);
      streakTimer = setTimeout(function () { streak = 0; }, 800);
    });
  }

  function enhance(player) {
    bindDoubleTap(player);
    var mark = function () { lastActive = player; };
    player.on(['play', 'useractive', 'seeking'], mark);
    player.el().addEventListener('pointerdown', mark);
  }

  // When the stream link has expired: fetch fresh sources, resume at the same second.
  function refreshOnExpiry(player, fetchSources) {
    var lastTry = 0;
    player.on('error', function () {
      var err = player.error();
      if (!err || Date.now() - lastTry < 60000) return;
      lastTry = Date.now();
      var t = player.currentTime() || 0;
      var wasPlaying = !player.paused() || player.hasStarted();
      fetchSources().then(function (sources) {
        if (!sources || !sources.length) return;
        player.error(null);
        player.src(sources);
        player.one('loadedmetadata', function () {
          player.currentTime(t);
          if (wasPlaying) { var p = player.play(); if (p && p.catch) p.catch(function () {}); }
        });
      }).catch(function () {});
    });
  }

  function isTyping(el) {
    if (!el) return false;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
  }

  function inView(player) {
    var el = player.el();
    if (!el || el.offsetParent === null) return false;
    var r = el.getBoundingClientRect();
    return r.bottom > 0 && r.top < window.innerHeight;
  }

  // Keys work without clicking into the player first: they drive the player last used,
  // or the one on screen. Keys pressed inside a player go through its own hotkeys.
  document.addEventListener('keydown', function (e) {
    if (isTyping(e.target) || (e.target.closest && e.target.closest('.video-js, button, a'))) return;
    var player = (lastActive && inView(lastActive)) ? lastActive : null;
    if (!player && window.videojs) {
      var all = Object.values(videojs.getPlayers()).filter(Boolean);
      for (var i = 0; i < all.length && !player; i++) if (inView(all[i])) player = all[i];
    }
    if (player) handleKey(player, e);
  });

  window.WingPlayer = {
    SKIP: SKIP,
    enhance: enhance,
    refreshOnExpiry: refreshOnExpiry,
    hotkeys: function (e) { handleKey(this, e); },
  };
})();
