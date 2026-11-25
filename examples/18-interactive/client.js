// A single connection handler owns the task, token, pending steering and request loop.
class InteractiveClient {
    task = null;
    running = false;
    waitingForAnswer = false;
    pending = [];

    constructor(onResult, onError) {
        this.onResult = onResult;
        this.onError = onError;
    }

    submit(text) {
        if (this.task === null) {
            this.task = { prompt: text, interactive_continue_id: null };
        } else {
            this.pending.push(text);
        }
        this.waitingForAnswer = false;
        if (!this.running) void this.run();
    }

    async run() {
        this.running = true;
        try {
            while (this.task !== null && !this.waitingForAnswer) {
                const request = {
                    ...this.task,
                    interactive_steering_message: this.pending.length
                        ? this.pending.splice(0).join('\n')
                        : null,
                };
                const response = await fetch(location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(request),
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error ?? 'Request failed.');

                // The fixed type is the only framework continuation discriminator.
                if (result?.type !== 'ai_continue') {
                    this.task = null;
                    this.pending.length = 0;
                    this.onResult(result);
                    break;
                }
                this.task.interactive_continue_id = result.continueId;
                this.waitingForAnswer = result.stop === true;
                this.onResult(result);
                // log_message continues automatically; ask_user waits for submit().
            }
        } catch (error) {
            this.task = null;
            this.pending.length = 0;
            this.onError(error);
        } finally {
            this.running = false;
        }
    }
}

const form = document.querySelector('#chat');
const input = document.querySelector('#prompt');
const events = document.querySelector('#events');
const append = text => { events.textContent += text + '\n'; };

const client = new InteractiveClient(result => {
    if (result?.type !== 'ai_continue') {
        append('AI: ' + result);
        return;
    }
    for (const call of result.toolCalls) append('Tool: ' + call.name);
    for (const message of result.logMessages) append('Log: ' + message);
    if (result.message !== null) append('AI: ' + result.message);
    for (const question of result.questions) append('Question: ' + question);
}, error => append('Error: ' + error.message));

form.addEventListener('submit', event => {
    event.preventDefault();
    const text = input.value.trim();
    if (text === '') return;
    input.value = '';
    append('You: ' + text);
    client.submit(text);
});
