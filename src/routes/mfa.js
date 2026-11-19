// Kétfaktoros hitelesítés útvonalai, közösen a lelkészi és a foglalói fiókokhoz.
const tf = require('../twofactor');
const { createLimiter } = require('../security');

const limiter = createLimiter(8, 15 * 60_000);
const wrap = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);

/** Beállítás / kikapcsolás: GET és POST `${base}/2fa`, POST `${base}/2fa/kikapcsolas`. */
function settingsRoutes(router, { kind, base, idOf, back, guard, mount = '' }) {
  // base: a böngészőben látható útvonal; mount: a router csatolási pontja (pl. '/admin'), amit a route-ok elől elhagyunk
  const r = base.slice(mount.length);
  const render = async (req, res, extra = {}) => {
    const id = idOf(req, res);
    const enabled = tf.isEnabled(kind, id);
    res.render('twofactor-setup', {
      title: 'Kétfaktoros hitelesítés', base, back, enabled,
      setup: enabled || extra.codes ? null : await tf.setupData(kind, id),
      backupLeft: tf.backupCount(kind, id), codes: null, error: null, ...extra,
    });
  };
  router.get(`${r}/2fa`, guard, wrap((req, res) => render(req, res)));
  router.post(`${r}/2fa`, guard, wrap(async (req, res) => {
    const id = idOf(req, res);
    const key = `${kind}:${id}:setup`;
    if (limiter.blocked(key)) { res.status(429); return render(req, res, { error: 'Túl sok hibás próbálkozás. Várjon 15 percet.' }); }
    const codes = tf.confirmSetup(kind, id, req.body.code);
    if (!codes) {
      limiter.fail(key);
      res.status(400);
      return render(req, res, { error: 'A kód nem megfelelő. Ellenőrizze, hogy a telefon órája pontos-e, és írja be az alkalmazásban éppen látható kódot.' });
    }
    limiter.reset(key);
    render(req, res, { codes });
  }));
  router.post(`${r}/2fa/kikapcsolas`, guard, wrap(async (req, res) => {
    const id = idOf(req, res);
    const key = `${kind}:${id}:disable`;
    if (limiter.blocked(key) || !tf.check(kind, id, req.body.code)) {
      limiter.fail(key);
      res.status(400);
      return render(req, res, { error: 'A kód nem megfelelő, a kétfaktoros hitelesítés bekapcsolva maradt.' });
    }
    tf.disable(kind, id);
    req.session.flash = { type: 'info', text: 'A kétfaktoros hitelesítést kikapcsolta.' };
    res.redirect(`${base}/2fa`);
  }));
}

/** Belépés második lépése: a jelszó után a hitelesítő kód. */
function verifyRoutes(router, { kind, path, loginPath, done, mount = '' }) {
  const route = path.slice(mount.length);
  const pending = (req) => {
    const m = req.session.mfa;
    return m && m.kind === kind && m.exp > Date.now() ? m : null;
  };
  router.get(route, (req, res) => {
    if (!pending(req)) return res.redirect(loginPath);
    res.render('twofactor-verify', { title: 'Ellenőrző kód', action: path, loginPath, error: null });
  });
  router.post(route, (req, res) => {
    const m = pending(req);
    if (!m) return res.redirect(loginPath);
    const key = `${kind}:${m.id}:login`;
    if (limiter.blocked(key)) {
      return res.status(429).render('twofactor-verify', { title: 'Ellenőrző kód', action: path, loginPath, error: 'Túl sok hibás próbálkozás. Várjon 15 percet.' });
    }
    if (!tf.check(kind, m.id, req.body.code)) {
      limiter.fail(key);
      return res.status(401).render('twofactor-verify', { title: 'Ellenőrző kód', action: path, loginPath, error: 'A kód nem megfelelő. Próbálja újra, vagy használjon tartalékkódot.' });
    }
    limiter.reset(key);
    delete req.session.mfa;
    done(req, res, m.id);
  });
}

/** Jelszó után: ha be van kapcsolva a 2FA, a második lépésre küld; különben belép. */
function afterPassword(req, res, { kind, id, verifyPath, done }) {
  if (tf.isEnabled(kind, id)) {
    req.session.mfa = { kind, id, exp: Date.now() + 5 * 60_000 };
    return res.redirect(verifyPath);
  }
  done(req, res, id);
}

module.exports = { settingsRoutes, verifyRoutes, afterPassword };
