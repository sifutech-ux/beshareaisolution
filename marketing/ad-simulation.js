'use strict'
window.BeShareAdSimulation = {
  units: { messages: 'perbualan bermula', clicks: 'klik pautan', reach: 'orang dicapai', engagement: 'interaksi hantaran' },
  range(sim) {
    if (sim.history_unavailable) return 'Sejarah Meta belum dapat dimuatkan. Anda masih boleh menyemak kos sasaran.'
    if (!sim.range) return 'Belum cukup rekod lengkap daripada kempen setanding untuk menganggarkan hasil. Kos sasaran dikira daripada bajet anda.'
    const [low, high] = sim.range
    const position = sim.target > high ? 'Sasaran anda melebihi julat ini.' : sim.target < low ? 'Sasaran anda di bawah julat ini.' : 'Sasaran anda dalam julat ini.'
    return 'Pada bajet ini, kadar hasil daripada ' + sim.sample + ' kempen BeShare setanding dalam 90 hari memberi julat ' +
      low.toLocaleString('ms-MY') + '–' + high.toLocaleString('ms-MY') + ' ' + this.units[sim.metric] + '. ' + position +
      ' Dalam rekod asal, ' + sim.historical_met + ' daripada ' + sim.sample + ' kempen (' + Math.round(100 * sim.historical_met / sim.sample) +
      '%) mencapai jumlah sasaran ini dengan belanja masing-masing.'
  }
}
