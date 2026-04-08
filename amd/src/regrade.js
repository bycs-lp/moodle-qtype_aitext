/**
 * AMD module for triggering AI regrading of aitext questions.
 *
 * @module     qtype_aitext/regrade
 * @copyright  2026 Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

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

        try {
            const confirmMessage = await getString('regrade_ai_confirm', 'qtype_aitext');
            if (!window.confirm(confirmMessage)) {
                button.disabled = false;
                return;
            }

            const result = await Ajax.call([{
                methodname: 'qtype_aitext_trigger_regrade',
                args: {attemptstepids: [stepId]},
            }])[0];

            await Notification.addNotification({
                message: result.message,
                type: 'success',
            });

            // Reload to show the updated state / progress bar.
            window.location.reload();
        } catch (error) {
            Notification.exception(error);
            button.disabled = false;
        }
    });
};
