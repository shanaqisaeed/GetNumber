/**
 * Calm, soothing marimba/chime ringtone synthesizer using Web Audio API
 * Produces a warm, acoustic-like, non-intrusive harmonic chime (F Major 7th arpeggio)
 */
export class CalmRingtone {
    private ctx: AudioContext | null = null;
    private timer: ReturnType<typeof setInterval> | null = null;
    private isPlaying = false;

    public play(): void {
        if (this.isPlaying) return;
        this.isPlaying = true;

        const AudioContextClass = window.AudioContext || (window as any).webkitAudioContext;
        if (!AudioContextClass) return;

        try {
            this.ctx = new AudioContextClass();
            if (this.ctx.state === 'suspended') {
                this.ctx.resume().catch(() => {});
            }

            const playPhrase = () => {
                if (!this.ctx || this.ctx.state === 'closed') return;
                const now = this.ctx.currentTime;

                // Calming, gentle major 7th chime sequence: F5 -> A5 -> C6 -> E6
                const notes = [
                    { freq: 698.46, time: 0.00, dur: 0.55 },
                    { freq: 880.00, time: 0.22, dur: 0.55 },
                    { freq: 1046.50, time: 0.44, dur: 0.65 },
                    { freq: 1318.51, time: 0.66, dur: 1.10 },
                    // Soothing resolution:
                    { freq: 1046.50, time: 1.25, dur: 0.50 },
                    { freq: 1318.51, time: 1.50, dur: 1.30 },
                ];

                for (const note of notes) {
                    try {
                        const osc = this.ctx.createOscillator();
                        const gain = this.ctx.createGain();
                        const filter = this.ctx.createBiquadFilter();

                        filter.type = 'lowpass';
                        filter.frequency.value = 2400; // Warm acoustic tone

                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(note.freq, now + note.time);

                        // Smooth acoustic envelope: soft attack + natural decay
                        gain.gain.setValueAtTime(0.0001, now + note.time);
                        gain.gain.linearRampToValueAtTime(0.07, now + note.time + 0.035);
                        gain.gain.exponentialRampToValueAtTime(0.0001, now + note.time + note.dur);

                        osc.connect(filter);
                        filter.connect(gain);
                        gain.connect(this.ctx.destination);

                        osc.start(now + note.time);
                        osc.stop(now + note.time + note.dur);
                    } catch {}
                }
            };

            playPhrase();
            this.timer = setInterval(playPhrase, 3200);
        } catch {}
    }

    public stop(): void {
        this.isPlaying = false;
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        if (this.ctx) {
            try {
                this.ctx.close();
            } catch {}
            this.ctx = null;
        }
    }
}

