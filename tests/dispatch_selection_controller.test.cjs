const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

test('select all checks private photos without changing disabled photos', () => {
    const source = fs.readFileSync(path.join(__dirname, '../assets/controllers/dispatch_selection_controller.js'), 'utf8')
        .replace(/^import .*;\s*$/gm, '')
        .replace('export default class', 'return class');
    const Controller = new Function('Controller', source)(class {});
    const instance = new Controller();
    instance.pictureTargets = [
        { checked: false, disabled: false },
        { checked: false, disabled: false },
        { checked: true, disabled: true },
    ];
    instance.selectAll();
    assert.deepEqual(instance.pictureTargets.map((picture) => picture.checked), [true, true, true]);
});
