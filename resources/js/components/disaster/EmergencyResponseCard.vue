<script setup lang="ts">
import {
    Activity,
    AlertOctagon,
    AlertTriangle,
    ExternalLink,
    HelpCircle,
    Info,
    LifeBuoy,
    Phone,
    PhoneCall,
    Shield,
    ShieldAlert,
    Waves,
    Wind,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';

interface EmergencyAgency {
    agency: string;
    acronym: string;
    description: string;
    primaryHotline: string;
    policeHotline?: string;
    medicalHotline?: string;
    fireHotline?: string;
    officialUrl: string;
}

const emergencyAgencies: Record<string, EmergencyAgency> = {
    Philippines: {
        agency: 'National Disaster Risk Reduction and Management Council',
        acronym: 'NDRRMC',
        description:
            'Lead policy, coordinating, and implementing body for disaster risk reduction and emergency response.',
        primaryHotline: '911',
        policeHotline: '911',
        medicalHotline: '143 (Red Cross)',
        fireHotline: '911',
        officialUrl: 'https://ndrrmc.gov.ph',
    },
    Indonesia: {
        agency: 'Badan Nasional Penanggulangan Bencana',
        acronym: 'BNPB',
        description:
            'Indonesian non-departmental government agency for disaster management and regional emergency response.',
        primaryHotline: '117',
        policeHotline: '110 / 112',
        medicalHotline: '118 / 119',
        fireHotline: '113',
        officialUrl: 'https://bnpb.go.id',
    },
    Thailand: {
        agency: 'Department of Disaster Prevention and Mitigation',
        acronym: 'DDPM',
        description:
            'Principal agency under the Ministry of Interior tasked with nationwide civil defense and hazard mitigation.',
        primaryHotline: '1784',
        policeHotline: '191',
        medicalHotline: '1669',
        fireHotline: '199',
        officialUrl: 'https://disaster.go.th',
    },
    Vietnam: {
        agency: 'Vietnam Disaster and Dyke Management Authority',
        acronym: 'VNDMA',
        description:
            'Specialized national authority under the Ministry of Agriculture and Rural Development for disaster safety.',
        primaryHotline: '112',
        policeHotline: '113',
        medicalHotline: '115',
        fireHotline: '114',
        officialUrl: 'https://phongchongthientai.mard.gov.vn',
    },
    Malaysia: {
        agency: 'Agensi Pengurusan Bencana Negara',
        acronym: 'NADMA',
        description:
            'Focal agency for national disaster management under the Prime Minister Department.',
        primaryHotline: '999',
        policeHotline: '999',
        medicalHotline: '999',
        fireHotline: '999',
        officialUrl: 'https://nadma.gov.my',
    },
    Myanmar: {
        agency: 'Department of Disaster Management',
        acronym: 'DDM',
        description:
            'Under Ministry of Social Welfare, Relief and Resettlement responsible for emergency response and relief.',
        primaryHotline: '191',
        policeHotline: '199',
        medicalHotline: '192',
        fireHotline: '191',
        officialUrl: 'http://www.ddm.gov.mm',
    },
    Cambodia: {
        agency: 'National Committee for Disaster Management',
        acronym: 'NCDM',
        description:
            'Coordinates national and sub-national disaster preparedness, emergency response, and recovery.',
        primaryHotline: '119',
        policeHotline: '117',
        medicalHotline: '119',
        fireHotline: '118',
        officialUrl: 'https://ncdm.gov.kh',
    },
    Laos: {
        agency: 'National Disaster Management Committee',
        acronym: 'NDMC',
        description:
            'Oversees disaster management and civil protection across all 18 provinces in Lao PDR.',
        primaryHotline: '1190',
        policeHotline: '1191',
        medicalHotline: '1195',
        fireHotline: '1190',
        officialUrl: 'http://www.socialprotection.gov.la',
    },
    Singapore: {
        agency: 'Singapore Civil Defence Force',
        acronym: 'SCDF',
        description:
            'Uniformed agency under Ministry of Home Affairs providing fire fighting, rescue, and emergency medical services.',
        primaryHotline: '995',
        policeHotline: '999',
        medicalHotline: '995',
        fireHotline: '995',
        officialUrl: 'https://scdf.gov.sg',
    },
    Brunei: {
        agency: 'National Disaster Management Centre',
        acronym: 'NDMC',
        description:
            'Focal agency responsible for orchestrating disaster risk reduction and community disaster safety.',
        primaryHotline: '995',
        policeHotline: '993',
        medicalHotline: '991',
        fireHotline: '995',
        officialUrl: 'http://www.ndmc.gov.bn',
    },
    'Timor-Leste': {
        agency: 'Direcção Nacional de Protecção Civil',
        acronym: 'DNPC',
        description:
            'National Directorate of Civil Protection managing disaster risk reduction and humanitarian aid.',
        primaryHotline: '112',
        policeHotline: '112',
        medicalHotline: '110',
        fireHotline: '115',
        officialUrl: 'https://dnpc.gov.tl',
    },
};

const props = defineProps<{
    selectedCountry: string;
}>();

const currentAgency = computed<EmergencyAgency | null>(() => {
    return emergencyAgencies[props.selectedCountry] ?? null;
});

const isRegional = computed(() => {
    return props.selectedCountry === 'All' || !currentAgency.value;
});
</script>

<template>
    <Card class="overflow-hidden border-border/80 bg-card shadow-sm">
        <CardContent class="space-y-4 p-5 sm:p-6">
            <!-- Header -->
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-destructive/10 text-destructive ring-1 ring-destructive/20"
                    >
                        <LifeBuoy class="size-5" />
                    </div>
                    <div>
                        <h3
                            class="flex items-center gap-2 text-sm font-bold text-foreground"
                        >
                            <span>Emergency Response & Civil Defense</span>
                            <span
                                v-if="currentAgency"
                                class="font-mono text-[10px] text-destructive uppercase"
                            >
                                {{ currentAgency.acronym }}
                            </span>
                        </h3>
                        <p class="text-[11px] text-muted-foreground">
                            {{
                                isRegional
                                    ? 'ASEAN regional humanitarian framework and emergency dispatch contacts'
                                    : `Official emergency response agency for ${selectedCountry}`
                            }}
                        </p>
                    </div>
                </div>

                <Badge
                    variant="outline"
                    class="border-destructive/30 bg-destructive/10 font-mono text-[10px] text-destructive"
                >
                    Civil Safety
                </Badge>
            </div>

            <!-- Regional (All ASEAN) Overview -->
            <div
                v-if="isRegional"
                class="space-y-3 rounded-xl border border-border/70 bg-muted/30 p-4 text-xs"
            >
                <div class="flex items-start gap-2.5">
                    <Shield class="mt-0.5 size-4 shrink-0 text-primary" />
                    <div class="space-y-1">
                        <h4 class="font-bold text-foreground">
                            AHA Centre (ASEAN Coordinating Centre for
                            Humanitarian Assistance)
                        </h4>
                        <p
                            class="text-[11px] leading-relaxed text-muted-foreground"
                        >
                            Established under the ASEAN Agreement on Disaster
                            Management and Emergency Response (AADMER) to
                            facilitate regional cooperation, joint disaster
                            assessment, and emergency relief distribution across
                            all 10 member states and Timor-Leste.
                        </p>
                        <div class="flex flex-wrap gap-3 pt-1 text-[11px]">
                            <span class="font-medium text-foreground"
                                >Headquarters: Jakarta, Indonesia</span
                            >
                            <a
                                href="https://ahacentre.org"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                            >
                                <span>ahacentre.org</span>
                                <ExternalLink class="size-3" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Specific Country Agency Information -->
            <div v-else class="space-y-3">
                <div
                    class="space-y-2 rounded-xl border border-border/70 bg-muted/30 p-4 text-xs"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="text-xs font-bold text-foreground sm:text-sm"
                        >
                            {{ currentAgency?.agency }}
                        </span>
                        <a
                            v-if="currentAgency?.officialUrl"
                            :href="currentAgency.officialUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
                        >
                            <span>Official Portal</span>
                            <ExternalLink class="size-3" />
                        </a>
                    </div>
                    <p
                        class="text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{ currentAgency?.description }}
                    </p>
                </div>

                <!-- Hotlines Grid -->
                <div
                    class="grid grid-cols-2 gap-2 pt-1 font-mono text-xs sm:grid-cols-4"
                >
                    <div
                        class="rounded-lg border border-destructive/25 bg-destructive/5 p-2.5"
                    >
                        <span
                            class="block font-sans text-[10px] font-semibold text-destructive uppercase"
                            >Emergency / Dispatch</span
                        >
                        <a
                            :href="`tel:${currentAgency?.primaryHotline}`"
                            class="mt-1 flex items-center gap-1.5 text-sm font-bold text-foreground transition-colors hover:text-destructive"
                        >
                            <PhoneCall
                                class="size-3.5 shrink-0 text-destructive"
                            />
                            <span>{{ currentAgency?.primaryHotline }}</span>
                        </a>
                    </div>

                    <div
                        class="rounded-lg border border-border/70 bg-card p-2.5"
                    >
                        <span
                            class="block font-sans text-[10px] font-semibold text-muted-foreground uppercase"
                            >Police Assistance</span
                        >
                        <a
                            :href="`tel:${currentAgency?.policeHotline}`"
                            class="mt-1 flex items-center gap-1.5 text-sm font-bold text-foreground transition-colors hover:text-primary"
                        >
                            <Phone
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span>{{ currentAgency?.policeHotline }}</span>
                        </a>
                    </div>

                    <div
                        class="rounded-lg border border-border/70 bg-card p-2.5"
                    >
                        <span
                            class="block font-sans text-[10px] font-semibold text-muted-foreground uppercase"
                            >Medical & Ambulance</span
                        >
                        <a
                            :href="`tel:${currentAgency?.medicalHotline}`"
                            class="mt-1 flex items-center gap-1.5 text-sm font-bold text-foreground transition-colors hover:text-primary"
                        >
                            <Phone
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span>{{ currentAgency?.medicalHotline }}</span>
                        </a>
                    </div>

                    <div
                        class="rounded-lg border border-border/70 bg-card p-2.5"
                    >
                        <span
                            class="block font-sans text-[10px] font-semibold text-muted-foreground uppercase"
                            >Fire & Rescue</span
                        >
                        <a
                            :href="`tel:${currentAgency?.fireHotline}`"
                            class="mt-1 flex items-center gap-1.5 text-sm font-bold text-foreground transition-colors hover:text-primary"
                        >
                            <Phone
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span>{{ currentAgency?.fireHotline }}</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Standard Immediate Action Protocols -->
            <div class="border-t border-border/60 pt-3">
                <span
                    class="mb-2 block text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                >
                    Universal Disaster Safety Protocols
                </span>
                <div
                    class="grid grid-cols-1 gap-2 text-[11px] text-muted-foreground sm:grid-cols-3"
                >
                    <div
                        class="flex items-start gap-1.5 rounded-lg border border-border/60 bg-muted/20 p-2"
                    >
                        <Activity
                            class="mt-0.5 size-3.5 shrink-0 text-orange-500"
                        />
                        <div>
                            <strong class="text-foreground">Earthquake:</strong>
                            Drop, cover, and hold on; stay away from exterior
                            glass and unanchored fixtures.
                        </div>
                    </div>

                    <div
                        class="flex items-start gap-1.5 rounded-lg border border-border/60 bg-muted/20 p-2"
                    >
                        <Waves class="mt-0.5 size-3.5 shrink-0 text-blue-500" />
                        <div>
                            <strong class="text-foreground"
                                >Flood / Inundation:</strong
                            >
                            Move to higher ground; never walk, swim, or drive
                            through moving water.
                        </div>
                    </div>

                    <div
                        class="flex items-start gap-1.5 rounded-lg border border-border/60 bg-muted/20 p-2"
                    >
                        <Wind class="mt-0.5 size-3.5 shrink-0 text-cyan-500" />
                        <div>
                            <strong class="text-foreground"
                                >Cyclone / Typhoon:</strong
                            >
                            Secure loose objects, monitor local advisories,
                            retreat to interior rooms.
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
