/* Only named, same-site workspaces may be used as the login destination. */
(function (root, factory) {
  const api = factory()
  if (typeof module === 'object' && module.exports) module.exports = api
  root.BeshareKembali = api
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict'
  function target(search) {
    const value = new URLSearchParams(typeof search === 'string' ? search : '').get('selepas')
    return value === 'marketing' ? '../marketing/' : '../penasihat/#utama'
  }
  return { target }
})
