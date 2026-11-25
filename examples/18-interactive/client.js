// A single connection handler owns the task, token, pending steering and request loop.
class InteractiveClient {
    task = null;
    continueId = null;
    running = false;
    waitingForAnswer = false;
    pending = [];

    constructor(onResult, onError) {
        this.onResult = onResult;
        this.onError = onError;
    }

    submit(text) {
        if (this.task === null) {
            this.task = { prompt: text, interactive_continue_id: this.continueId };
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

                // Both intermediate and completed responses have the same type.
                if (result?.type !== 'ai_continue') throw new Error('Unexpected response type.');
                this.continueId = result.continueId;
                this.onResult(result);

                if (result.completed) {
                    // Keep the final token for the next task in this conversation.
                    this.task = this.pending.length
                        ? { prompt: this.pending.splice(0).join('\n'), interactive_continue_id: this.continueId }
                        : null;
                    continue;
                }

                this.task.interactive_continue_id = result.continueId;
                this.waitingForAnswer = result.stop === true;
                // log_message continues automatically; ask_user waits for submit().
            }
        } catch (error) {
            this.task = null;
            this.continueId = null;
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
    for (const call of result.toolCalls) append('Tool: ' + call.name);
    for (const message of result.logMessages) append('Log: ' + message);
    if (result.message !== null) append('AI: ' + result.message);
    for (const question of result.questions) append('Question: ' + question);
    if (result.completed) append('AI: ' + result.result);
}, error => append('Error: ' + error.message));

form.addEventListener('submit', event => {
    event.preventDefault();
    const text = input.value.trim();
    if (text === '') return;
    input.value = '';
    append('You: ' + text);
    client.submit(text);
});
