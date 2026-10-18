/*! AlfaKit Analytics: no cookies, no storage, honours DNT and GPC */
;(function (w, d, n) {
  var s = d.currentScript,
    k = s && s.getAttribute('data-site'),
    api = (s && s.getAttribute('data-api')) || '/alfakit-analytics/event',
    last
  if (
    !k ||
    n.doNotTrack == '1' ||
    w.doNotTrack == '1' ||
    n.msDoNotTrack == '1' ||
    n.globalPrivacyControl ||
    n.webdriver
  )
    return
  if (/^(localhost|127\.|\[::1\])/.test(location.hostname) && !s.hasAttribute('data-local')) return
  function send(e, p) {
    var b = JSON.stringify({
      k: k,
      n: e || 'pageview',
      u: location.href,
      r: d.referrer || null,
      p: p || null,
    })
    if (!(n.sendBeacon && n.sendBeacon(api, b)))
      fetch(api, { method: 'POST', body: b, keepalive: true, credentials: 'omit' })['catch'](
        function () {},
      )
  }
  function view() {
    if (last === location.pathname) return
    last = location.pathname
    send()
  }
  var h = w.history,
    push = h && h.pushState
  if (push) {
    h.pushState = function () {
      push.apply(this, arguments)
      view()
    }
    w.addEventListener('popstate', view)
  }
  w.alfakit = w.alfakit || {}
  w.alfakit.track = function (e, p) {
    send(e, p)
  }
  d.addEventListener(
    'click',
    function (e) {
      var t = e.target && e.target.closest && e.target.closest('[data-alfakit-event]'),
        p = {},
        a,
        i
      if (!t) return
      for (i = 0; i < t.attributes.length; i++) {
        a = t.attributes[i]
        if (a.name.indexOf('data-alfakit-prop-') === 0) p[a.name.slice(18)] = a.value
      }
      send(t.getAttribute('data-alfakit-event'), p)
    },
    true,
  )
  if (d.visibilityState === 'prerender') {
    d.addEventListener('visibilitychange', function f() {
      if (d.visibilityState !== 'prerender') {
        d.removeEventListener('visibilitychange', f)
        view()
      }
    })
  } else view()
})(window, document, navigator)
