/**
 * AMD module for triggering AI regrading of aitext questions.
 *
 * @module     qtype_aitext/regrade
 * @copyright  2026 ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import Pending from 'core/pending';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';

let initialised = false;

/**
 * Initialise the regrade button click handlers.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('click', async(e) => {
        const button = e.target.closest('.qtype-aitext-trigger-regrade');
        if (!button) {
            return;
        }

        e.preventDefault();
        button.disabled = true;

        const stepId = parseInt(button.dataset.attemptstepid, 10);
        if (!stepId) {
            button.disabled = false;
            return;
        }

        const pendingPromise = new Pending('qtype_aitext/regrade:triggering');

        try {
            const confirmTitle = await getString('regrade_ai', 'qtype_aitext');
            const confirmMessage = await getString('regrade_ai_confirm', 'qtype_aitext');

            const modal = await ModalSaveCancel.create({
                title: confirmTitle,
                body: confirmMessage,
                buttons: {
                    save: await getString('regrade_ai', 'qtype_aitext'),
                },
                show: true,
            });

            modal.getRoot().on(ModalEvents.save, async() => {
                try {
                    const result = await Ajax.call([{
                        methodname: 'qtype_aitext_trigger_regrade',
                        args: {attemptstepids: [stepId]},
                    }])[0];

                    await Notification.addNotification({
                        message: result.message,
                        type: result.count > 0 ? 'success' : 'warning',
                    });

                    pendingPromise.resolve();

                    // Reload to show the updated state / progress bar.
                    window.location.reload();
                } catch (error) {
                    pendingPromise.resolve();
                    Notification.exception(error);
                    button.disabled = false;
                }
            });

            modal.getRoot().on(ModalEvents.cancel, () => {
                button.disabled = false;
                pendingPromise.resolve();
            });

            modal.getRoot().on(ModalEvents.hidden, () => {
                button.disabled = false;
                pendingPromise.resolve();
                modal.destroy();
            });
        } catch (error) {
            pendingPromise.resolve();
            Notification.exception(error);
            button.disabled = false;
        }
    });
};
