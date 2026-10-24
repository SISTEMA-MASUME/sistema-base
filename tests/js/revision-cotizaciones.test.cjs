const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

function editor(guardar) {
    const window = {};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../resources/js/revision-cotizaciones.js'), 'utf8'), { window });
    const state = window.utilidadRevision(7, '30.00', 'v1');
    const eventos = [];
    state.$dispatch = (_, data) => eventos.push(data.cambio);
    state.$nextTick = callback => callback();
    state.$refs = { porcentaje: { focus() {}, select() {} } };
    state.$wire = { guardarUtilidad: guardar };
    state.iniciar({ dataset: { valor: '30.00', version: 'v1' } });
    state.valor = '25';
    return { state, eventos };
}

test('Listo y blur simultáneos guardan una vez y cierran solo al confirmar el servidor', async () => {
    let finalizar;
    let llamadas = 0;
    const { state, eventos } = editor((id, valor, version) => {
        llamadas++;
        assert.equal(id, 7);
        assert.equal(valor, '25');
        assert.equal(version, 'v1');
        return new Promise(resolve => { finalizar = resolve; });
    });
    const guardado = state.guardar();
    await state.guardar();
    assert.equal(llamadas, 1);
    assert.equal(state.editando, true);
    assert.equal(state.guardando, true);
    state.cancelar();
    assert.equal(state.editando, true);
    finalizar({ valor: '25.00', version: 'v2' });
    await guardado;
    assert.equal(state.editando, false);
    assert.equal(state.guardando, false);
    assert.equal(state.version, 'v2');
    assert.deepEqual(eventos, [1, -1]);
});

test('Una validación conserva el valor escrito y la edición pendiente', async () => {
    const { state, eventos } = editor(async () => null);
    await state.guardar();
    assert.equal(state.valor, '25');
    assert.equal(state.editando, true);
    assert.equal(state.guardando, false);
    assert.deepEqual(eventos, [1]);
});

test('Un fallo de conexión permite reintentar sin perder el porcentaje', async () => {
    const { state, eventos } = editor(async () => { throw new Error('Sin conexión'); });
    await state.guardar();
    assert.match(state.error, /No se pudo guardar/);
    assert.equal(state.valor, '25');
    assert.equal(state.editando, true);
    assert.equal(state.guardando, false);
    assert.deepEqual(eventos, [1]);
});

test('Escape cancela y el blur posterior no guarda', async () => {
    let llamadas = 0;
    const { state, eventos } = editor(async () => { llamadas++; });
    state.cancelar();
    await state.guardar();
    assert.equal(llamadas, 0);
    assert.equal(state.editando, false);
    assert.deepEqual(eventos, [1, -1]);
});
