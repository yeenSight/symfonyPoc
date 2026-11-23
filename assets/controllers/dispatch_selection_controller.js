import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['picture'];

    selectAll() {
        this.pictureTargets.forEach((picture) => {
            if (!picture.disabled) {
                picture.checked = true;
            }
        });
    }
}
