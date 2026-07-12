<?php

use App\Support\Cpu;

describe('workerProcesses', function () {
    it('fills a dedicated box with whole encodes', function () {
        // 9950X, 32 GB: CPU allows 32/4 = 8, RAM allows (32 - 6.4) / 3 = 8.
        expect(Cpu::workerProcesses(32, 32.0))->toBe(8);
    });

    it('lets RAM win when the box has cores to spare', function () {
        // The staging failure: 30 cores would allow 7, but 17 GB only holds 4 encodes.
        expect(Cpu::workerProcesses(30, 17.0))->toBe(4);
    });

    it('lets the CPU win when the box has RAM to spare', function () {
        expect(Cpu::workerProcesses(8, 64.0))->toBe(2);
    });

    it('scales up to a large node', function () {
        expect(Cpu::workerProcesses(64, 128.0))->toBe(16);
    });

    it('still encodes on a box too small for one full budget', function () {
        expect(Cpu::workerProcesses(2, 4.0))->toBe(1)
            ->and(Cpu::workerProcesses(1, 1.0))->toBe(1);
    });

    it('never oversubscribes the RAM it was given', function () {
        foreach ([[4, 8.0], [8, 16.0], [16, 32.0], [30, 17.0], [32, 32.0], [64, 128.0]] as [$cores, $gb]) {
            $processes = Cpu::workerProcesses($cores, $gb);
            $threads = Cpu::threadsPerEncode($cores, $processes);

            // 3 GB per encode, and a single process is allowed to exceed a tiny box's budget.
            expect($processes * 3)->toBeLessThanOrEqual(max(3, (int) ($gb * 0.8)))
                ->and($processes * $threads)->toBeLessThanOrEqual($cores);
        }
    });
});

describe('threadsPerEncode', function () {
    it('hands each encode the node fair share', function () {
        expect(Cpu::threadsPerEncode(32, 8))->toBe(4)
            ->and(Cpu::threadsPerEncode(30, 4))->toBe(7);
    });

    it('caps the share so a lone encode does not chase idle threads', function () {
        expect(Cpu::threadsPerEncode(64, 1))->toBe(8);
    });

    it('never drops below one thread', function () {
        expect(Cpu::threadsPerEncode(2, 8))->toBe(1);
    });
});
