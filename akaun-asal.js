/* Peta asal ke perkhidmatan Copilot. Bukan pautan IPO. */
(function (root, factory) {
  const api = factory()
  if (typeof module === 'object' && module.exports) module.exports = api
  root.BeshareAsal = api
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict'

  function asalPerkhidmatan(origin) {
    const peta = {
      'https://beshareaisolution.com': {
        akaun: 'https://akaun.beshareaisolution.com',
        nasihat: 'https://nasihat.beshareaisolution.com',
      },
      'https://www.beshareaisolution.com': {
        akaun: 'https://akaun.beshareaisolution.com',
        nasihat: 'https://nasihat.beshareaisolution.com',
      },
      'https://staging.beshareaisolution.com': {
        akaun: 'https://akaun-staging.beshareaisolution.com',
        nasihat: 'https://nasihat-staging.beshareaisolution.com',
      },
      'http://127.0.0.1:8000': {
        akaun: 'http://127.0.0.1:8767',
        nasihat: 'http://127.0.0.1:3210',
      },
      'http://localhost:8000': {
        akaun: 'http://127.0.0.1:8767',
        nasihat: 'http://127.0.0.1:3210',
      },
    }
    if (typeof origin !== 'string' || !Object.prototype.hasOwnProperty.call(peta, origin)) return null
    const pilihan = peta[origin]
    if (!pilihan || pilihan.akaun.includes('beshare-ipo-bot.onrender.com') || pilihan.nasihat.includes('beshare-ipo-bot.onrender.com')) return null
    return pilihan
  }
  /* tamat asal */

  return { asalPerkhidmatan }
})
