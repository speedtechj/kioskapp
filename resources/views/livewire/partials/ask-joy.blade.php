<div class="ask-joy" x-data="{
    question: '', messages: [], busy: false,
    money(amount) {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount / 100)
    },
    scrollToLatest() {
        $nextTick(() => { this.$refs.conversation.scrollTop = this.$refs.conversation.scrollHeight })
    },
    async send(value) {
        const question = value.trim()
        if (!question || this.busy) return
        this.busy = true
        this.question = ''
        this.messages.push({ question })
        this.scrollToLatest()
        try {
            const reply = await $wire.askJoy(question)
            this.messages.push({ answer: reply.answer || reply.error || 'Ask Joy is currently unavailable.', item: reply.item })
        } catch {
            this.messages.push({ answer: 'Ask Joy is currently unavailable. You can still browse the menu.' })
        } finally {
            this.busy = false
            this.scrollToLatest()
        }
    },
    async viewItem(id) {
        this.$refs.assistant.close()
        @if($takeout)
            await $wire.showItem(id)
        @else
            await $wire.revealAssistantItem(id)
            const item = document.getElementById('customer-menu-item-' + id)
            item?.focus({ preventScroll: true })
            item?.scrollIntoView({ block: 'center' })
        @endif
    }
}">
    <button type="button" class="ask-joy-trigger" aria-haspopup="dialog" x-on:click="$refs.assistant.showModal()">
        <span aria-hidden="true">✧</span> Ask Joy
    </button>
    <dialog class="ask-joy-dialog" aria-labelledby="ask-joy-title" x-ref="assistant" wire:ignore>
        <div class="ask-joy-panel">
            <header class="ask-joy-header">
                <div><h2 id="ask-joy-title"><span aria-hidden="true">✧</span> Ask Joy</h2><p>AI Menu Assistant</p></div>
                <button type="button" class="ask-joy-close" aria-label="Close Ask Joy" x-on:click="$refs.assistant.close()" autofocus>×</button>
            </header>
            <div class="ask-joy-conversation" x-ref="conversation">
                <div class="ask-joy-welcome" x-show="!messages.length">
                    <img src="{{ ($takeout ? $kiosk : $table->kiosk)->logoUrl() }}" alt="" width="70" height="70">
                    <h3>Hi! Ano’ng gusto mong kainin?</h3>
                    <div class="ask-joy-suggestions">
                        @foreach (['Under ₱100', 'Any promos?', 'Extra toppings'] as $suggestion)
                            <button type="button" x-on:click="send(@js($suggestion))">{{ $suggestion }}</button>
                        @endforeach
                    </div>
                </div>
                <div role="log" aria-label="Conversation">
                    <template x-for="(message, index) in messages" :key="index">
                        <div class="ask-joy-message" :class="message.question ? 'ask-joy-question' : 'ask-joy-answer'">
                            <p x-text="message.question || message.answer"></p>
                            <template x-if="message.item">
                                <article class="ask-joy-item">
                                    <img :src="message.item.image" alt="">
                                    <div>
                                        <strong x-text="message.item.name"></strong>
                                        <p x-text="money(message.item.price)"></p>
                                        <template x-if="message.item.topping">
                                            <div class="ask-joy-price">
                                                <span x-text="message.item.topping.name"></span>
                                                <span x-text="'+' + money(message.item.topping.price)"></span>
                                            </div>
                                        </template>
                                        <p x-show="message.item.topping"><strong x-text="'Item total ' + money(message.item.total)"></strong></p>
                                        <button type="button" x-on:click="viewItem(message.item.id)">View item</button>
                                    </div>
                                </article>
                            </template>
                        </div>
                    </template>
                </div>
                <p x-show="busy" role="status">Ask Joy is replying…</p>
            </div>
            <form class="ask-joy-composer" x-on:submit.prevent="send(question)">
                <input x-model="question" maxlength="400" placeholder="Ask in English or Tagalog" aria-label="Ask in English or Tagalog">
                <button type="submit" aria-label="Send message" :disabled="busy || !question.trim()">➤</button>
            </form>
        </div>
    </dialog>
</div>
