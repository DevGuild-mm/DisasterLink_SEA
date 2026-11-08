<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
</script>

<template>
    <footer
        class="border-t border-border bg-card/60 py-10 text-xs text-muted-foreground transition-colors duration-200"
    >
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-8 md:grid-cols-4">
                <!-- Brand summary -->
                <div class="space-y-3 md:col-span-2">
                    <div class="flex items-center gap-2">
                        <div
                            class="flex size-7 items-center justify-center rounded-lg bg-destructive/10 text-destructive ring-1 ring-destructive/20"
                        >
                            <ShieldAlert class="size-4" />
                        </div>
                        <span class="text-sm font-bold text-foreground">
                            DisasterLink<span class="text-destructive"
                                >SEA</span
                            >
                        </span>
                    </div>
                    <p class="max-w-md text-xs leading-relaxed">
                        Southeast Asian disaster telemetry and environmental
                        risk assessment aggregator. Consolidating real-time
                        feeds from international geological and atmospheric
                        monitoring institutes.
                    </p>
                    <p
                        class="max-w-md text-[11px] leading-relaxed text-muted-foreground/85"
                    >
                        <strong class="text-foreground">Safety Notice:</strong>
                        Telemetry is compiled from automated public APIs (GDACS,
                        USGS, NASA EONET, Open-Meteo). In emergency situations,
                        strictly follow directives from national disaster
                        management offices (NDMOs) and local civil defense
                        authorities.
                    </p>
                </div>

                <!-- Navigation Modules -->
                <div class="space-y-2">
                    <span
                        class="block text-xs font-bold tracking-wider text-foreground uppercase"
                    >
                        Monitoring Modules
                    </span>
                    <ul class="space-y-1.5 text-[11px]">
                        <li>
                            <Link
                                href="/"
                                class="transition-colors hover:text-foreground hover:underline"
                            >
                                Home
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/overview"
                                class="transition-colors hover:text-foreground hover:underline"
                            >
                                Regional Overview
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/map"
                                class="transition-colors hover:text-foreground hover:underline"
                            >
                                Interactive Disaster Map
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/events"
                                class="transition-colors hover:text-foreground hover:underline"
                            >
                                Live Disaster Events
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/weather"
                                class="transition-colors hover:text-foreground hover:underline"
                            >
                                ASEAN Weather Monitoring
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/freshness"
                                class="transition-colors hover:text-foreground hover:underline"
                            >
                                Data Provenance & Freshness
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- ASEAN Coverage -->
                <div class="space-y-2">
                    <span
                        class="block text-xs font-bold tracking-wider text-foreground uppercase"
                    >
                        ASEAN 11 States
                    </span>
                    <div class="grid grid-cols-2 gap-1 text-[11px]">
                        <span>• Brunei</span>
                        <span>• Cambodia</span>
                        <span>• Indonesia</span>
                        <span>• Laos</span>
                        <span>• Malaysia</span>
                        <span>• Myanmar</span>
                        <span>• Philippines</span>
                        <span>• Singapore</span>
                        <span>• Thailand</span>
                        <span>• Timor-Leste</span>
                        <span>• Vietnam</span>
                    </div>
                </div>
            </div>

            <div
                class="mt-8 flex flex-col items-center justify-between gap-4 border-t border-border/60 pt-6 text-[11px] sm:flex-row"
            >
                <p>&copy; 2026 DisasterLinkSEA. All rights reserved.</p>
                <div class="flex flex-wrap items-center gap-4">
                    <Link
                        href="/"
                        class="transition-colors hover:text-foreground hover:underline"
                        >Home</Link
                    >
                    <Link
                        href="/overview"
                        class="transition-colors hover:text-foreground hover:underline"
                        >Overview</Link
                    >
                    <Link
                        href="/map"
                        class="transition-colors hover:text-foreground hover:underline"
                        >Disaster Map</Link
                    >
                    <Link
                        href="/events"
                        class="transition-colors hover:text-foreground hover:underline"
                        >Live Events</Link
                    >
                    <Link
                        href="/weather"
                        class="transition-colors hover:text-foreground hover:underline"
                        >Weather</Link
                    >
                    <Link
                        href="/freshness"
                        class="transition-colors hover:text-foreground hover:underline"
                        >Data Freshness</Link
                    >
                </div>
            </div>
        </div>
    </footer>
</template>
