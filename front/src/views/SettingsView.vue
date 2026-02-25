<script setup lang="ts">
import { ref } from 'vue'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import RadarrSettings from '@/components/settings/RadarrSettings.vue'
import MediaPlayerSettings from '@/components/settings/MediaPlayerSettings.vue'
import VolumeSettings from '@/components/settings/VolumeSettings.vue'
import DiscordSettings from '@/components/settings/DiscordSettings.vue'
import GeneralSettings from '@/components/settings/GeneralSettings.vue'

const activeTab = ref('0')
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-bold text-gray-900">Paramètres</h1>

    <div class="bg-white rounded-lg border border-gray-200">
      <Tabs v-model:value="activeTab">
        <TabList>
          <Tab value="0">
            <div class="flex items-center gap-2">
              <i class="pi pi-video"></i>
              <span>Radarr</span>
            </div>
          </Tab>
          <Tab value="1">
            <div class="flex items-center gap-2">
              <i class="pi pi-play"></i>
              <span>Lecteurs</span>
            </div>
          </Tab>
          <Tab value="2">
            <div class="flex items-center gap-2">
              <i class="pi pi-database"></i>
              <span>Volumes</span>
            </div>
          </Tab>
          <Tab value="3">
            <div class="flex items-center gap-2">
              <i class="pi pi-discord"></i>
              <span>Discord</span>
            </div>
          </Tab>
          <Tab value="4">
            <div class="flex items-center gap-2">
              <i class="pi pi-cog"></i>
              <span>Général</span>
            </div>
          </Tab>
        </TabList>

        <TabPanels>
          <TabPanel value="0">
            <div class="p-4">
              <RadarrSettings />
            </div>
          </TabPanel>
          <TabPanel value="1">
            <div class="p-4">
              <MediaPlayerSettings />
            </div>
          </TabPanel>
          <TabPanel value="2">
            <div class="p-4">
              <VolumeSettings />
            </div>
          </TabPanel>
          <TabPanel value="3">
            <div class="p-4">
              <DiscordSettings />
            </div>
          </TabPanel>
          <TabPanel value="4">
            <div class="p-4">
              <GeneralSettings />
            </div>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </div>
  </div>
</template>
