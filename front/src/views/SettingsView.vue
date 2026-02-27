<script setup lang="ts">
import { ref } from 'vue'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import WatcherSettings from '@/components/settings/WatcherSettings.vue'
import RadarrSettings from '@/components/settings/RadarrSettings.vue'
import MediaPlayerSettings from '@/components/settings/MediaPlayerSettings.vue'
import TorrentSettings from '@/components/settings/TorrentSettings.vue'
import DiscordSettings from '@/components/settings/DiscordSettings.vue'
import PresetsSettings from '@/components/settings/PresetsSettings.vue'
import TrackerRulesSettings from '@/components/settings/TrackerRulesSettings.vue'
import GeneralSettings from '@/components/settings/GeneralSettings.vue'

const activeTab = ref('0')
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-bold text-gray-900">Paramètres</h1>

    <div class="bg-white rounded-lg border border-gray-200">
      <Tabs v-model:value="activeTab" :lazy="true">
        <TabList>
          <Tab value="0">
            <div class="flex items-center gap-2">
              <i class="pi pi-desktop"></i>
              <span>Watchers</span>
            </div>
          </Tab>
          <Tab value="1">
            <div class="flex items-center gap-2">
              <i class="pi pi-video"></i>
              <span>Radarr</span>
            </div>
          </Tab>
          <Tab value="2">
            <div class="flex items-center gap-2">
              <i class="pi pi-play"></i>
              <span>Lecteurs</span>
            </div>
          </Tab>
          <Tab value="3">
            <div class="flex items-center gap-2">
              <i class="pi pi-download"></i>
              <span>Torrent</span>
            </div>
          </Tab>
          <Tab value="4">
            <div class="flex items-center gap-2">
              <i class="pi pi-discord"></i>
              <span>Discord</span>
            </div>
          </Tab>
          <Tab value="5">
            <div class="flex items-center gap-2">
              <i class="pi pi-sliders-h"></i>
              <span>Presets</span>
            </div>
          </Tab>
          <Tab value="6">
            <div class="flex items-center gap-2">
              <i class="pi pi-shield"></i>
              <span>Trackers</span>
            </div>
          </Tab>
          <Tab value="7">
            <div class="flex items-center gap-2">
              <i class="pi pi-globe"></i>
              <span>TMDB</span>
            </div>
          </Tab>
        </TabList>

        <TabPanels>
          <TabPanel value="0">
            <div class="p-4">
              <WatcherSettings />
            </div>
          </TabPanel>
          <TabPanel value="1">
            <div class="p-4">
              <RadarrSettings />
            </div>
          </TabPanel>
          <TabPanel value="2">
            <div class="p-4">
              <MediaPlayerSettings />
            </div>
          </TabPanel>
          <TabPanel value="3">
            <div class="p-4">
              <TorrentSettings />
            </div>
          </TabPanel>
          <TabPanel value="4">
            <div class="p-4">
              <DiscordSettings />
            </div>
          </TabPanel>
          <TabPanel value="5">
            <div class="p-4">
              <PresetsSettings />
            </div>
          </TabPanel>
          <TabPanel value="6">
            <div class="p-4">
              <TrackerRulesSettings />
            </div>
          </TabPanel>
          <TabPanel value="7">
            <div class="p-4">
              <GeneralSettings />
            </div>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </div>
  </div>
</template>
