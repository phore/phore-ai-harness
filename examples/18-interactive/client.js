const form = document.querySelector('#chat');
const input = document.querySelector('#prompt');
const events = document.querySelector('#events');

let responseId = null;
let running = false;
const pending = [];

async function run() {
    running = true;
    try {
        let result;
        do {
            // New user input is consumed once. No repeated initial prompt.
            const prompt = pending.shift() ?? null;
            const response = await fetch(location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt, responseId }),
            });
            result = await response.json();
            if (!response.ok) {
                throw new Error(result.error ?? 'Request failed.');
            }
            responseId = result.responseId;
            for (const call of result.toolCalls) {
                events.textContent += 'Tool: ' + call.name + ' (' + call.callId + ')\n';
            }
            if (result.message !== null) {
                events.textContent += 'AI: ' + result.message + '\n';
            }
        } while (result.continue || pending.length > 0);
    } catch (error) {
        // The server may have applied side effects. Never replay this step blindly.
        pending.length = 0;
        responseId = null;
        events.textContent += 'Error: ' + error.message + '\nStart a new task after checking the previous outcome.\n';
    } finally {
        running = false;
    }
}

form.addEventListener('submit', event => {
    event.preventDefault();
    const prompt = input.value.trim();
    if (prompt === '') return;
    pending.push(prompt);
    input.value = '';
    events.textContent += 'You: ' + prompt + '\n';
    if (!running) void run();
});
