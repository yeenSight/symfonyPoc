const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../assets/controllers/bulk_upload_controller.js'), 'utf8')
    .replace(/^import .*;\s*$/gm, '')
    .replace('export default class', 'return class');

class Modal {
    show() { this.shown = true; }
    dispose() {}
}

class Option {
    constructor(text, value) {
        this.text = text;
        this.value = value;
        this.dataset = {};
    }
}

class FormData {
    constructor() { this.fields = {}; }
    append(name, value) { this.fields[name] = value; }
}

function controller(isDispatch = true, fetch = async () => ({
        ok: true,
        json: async () => ({ id: 12, name: 'Event', idCode: '012345', code: '067890' }),
    })) {
    const Controller = new Function('Controller', 'Modal', 'Option', 'FormData', 'fetch', 'document', source)(
        class {}, Modal, Option, FormData, fetch, { createElement: () => ({}) },
    );
    const instance = new Controller();
    instance.modalTarget = { addEventListener() {} };
    instance.hasDispatchResultModalTarget = isDispatch;
    instance.hasDispatchTarget = isDispatch;
    instance.dispatchResultModalTarget = {};
    instance.connect();
    instance.dispatchTarget = {
        options: [],
        add(option) { this.options.push(option); },
        get selectedOptions() { return this.options.filter((option) => option.value === this.value); },
    };
    instance.dispatchNameTarget = { value: 'Event' };
    instance.dispatchSaveTarget = {};
    instance.dispatchMessageTarget = {};
    instance.dispatchCredentialsTarget = { append() {} };
    instance.dispatchResultIdentifierTarget = {};
    instance.dispatchResultCodeTarget = {};
    instance.dispatchResetRetryTarget = {};
    instance.dispatchResetUrlValue = '/admin/pictures/bulk/dispatch/0/code';
    instance.dispatchResetTokenValue = 'reset-token';
    instance.submitTarget = {};
    instance.summaryTarget = {};
    instance.refresh = () => {};
    return instance;
}

test('successful upload displays the selected new dispatch identifier and password', async () => {
    const instance = controller();
    await instance.createDispatch({ preventDefault() {} });
    instance.items = [{ done: false }];
    instance.uploadItem = async () => true;
    await instance.upload();

    assert.equal(instance.dispatchResultModal.shown, true);
    assert.equal(instance.dispatchResultIdentifierTarget.textContent, '012345');
    assert.equal(instance.dispatchResultCodeTarget.textContent, '067890');
});

test('a later upload to a newly created dispatch generates a new password', async () => {
    let resets = 0;
    const instance = controller(true, async (url) => {
        if (url === '/create') {
            return { ok: true, json: async () => ({ id: 12, name: 'Event', idCode: '012345', code: '067890' }) };
        }
        resets++;
        return { ok: true, json: async () => ({ idCode: '012345', code: '055555' }) };
    });
    instance.dispatchUrlValue = '/create';
    await instance.createDispatch({ preventDefault() {} });
    instance.items = [{ done: false }];
    instance.uploadItem = async () => true;
    await instance.upload();
    assert.equal(resets, 0);
    instance.items = [{ done: false }];
    await instance.upload();
    assert.equal(resets, 1);
    assert.equal(instance.dispatchResultCodeTarget.textContent, '055555');
});

test('existing dispatch gets a new access code after a successful upload', async () => {
    let request;
    const instance = controller(true, async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ idCode: '001234', code: '222333' }) };
    });
    const option = new Option('Existing', '99');
    option.dataset.idCode = '001234';
    instance.dispatchTarget.add(option);
    instance.dispatchTarget.value = '99';
    instance.items = [{ done: false }];
    instance.uploadItem = async () => true;
    await instance.upload();

    assert.equal(instance.dispatchResultModal.shown, true);
    assert.equal(instance.dispatchResultIdentifierTarget.textContent, '001234');
    assert.equal(instance.dispatchResultCodeTarget.textContent, '222333');
    assert.equal(request.url, '/admin/pictures/bulk/dispatch/99/code');
    assert.equal(request.options.body.fields._token, 'reset-token');
});

test('failed code generation can be retried without uploading the photos again', async () => {
    let attempts = 0;
    const instance = controller(true, async () => {
        if (++attempts === 1) {
            throw new Error('Network error');
        }
        return { ok: true, json: async () => ({ idCode: '001234', code: '222333' }) };
    });
    const option = new Option('Existing', '99');
    option.dataset.idCode = '001234';
    instance.dispatchTarget.add(option);
    instance.dispatchTarget.value = '99';
    instance.items = [{ done: false }];
    let uploads = 0;
    instance.uploadItem = async () => { uploads++; return true; };
    await instance.upload();

    assert.equal(instance.dispatchResultModal.shown, undefined);
    assert.equal(instance.dispatchResetRetryTarget.hidden, false);
    assert.equal(instance.dispatchTarget.disabled, true);
    await instance.retryDispatchReset();
    assert.equal(uploads, 1);
    assert.equal(instance.dispatchResultCodeTarget.textContent, '222333');
    assert.equal(instance.dispatchTarget.disabled, false);
});

test('failed or normal uploads do not show a dispatch success popup', async () => {
    const dispatch = controller();
    await dispatch.createDispatch({ preventDefault() {} });
    dispatch.items = [{ done: false }];
    dispatch.uploadItem = async () => false;
    await dispatch.upload();
    assert.equal(dispatch.dispatchResultModal.shown, undefined);
    assert.match(dispatch.summaryTarget.textContent, /1 need your attention/);

    const normal = controller(false);
    normal.items = [{ done: false }];
    normal.uploadItem = async () => true;
    await normal.upload();
    assert.equal(normal.dispatchResultModal, undefined);
    assert.match(normal.summaryTarget.textContent, /1 photo\(s\) uploaded/);
});
