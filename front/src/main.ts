import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import Tooltip from 'primevue/tooltip'
import App from './App.vue'
import router from './router'

import 'primeicons/primeicons.css'
import './assets/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(PrimeVue, {
  theme: {
    preset: Aura,
  },
  locale: {
    dayNames: ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'],
    dayNamesShort: ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'],
    dayNamesMin: ['Di', 'Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa'],
    monthNames: [
      'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
      'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
    ],
    monthNamesShort: [
      'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun',
      'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc',
    ],
    today: "Aujourd'hui",
    clear: 'Effacer',
    weekHeader: 'Sem',
    firstDayOfWeek: 1,
    dateFormat: 'dd/mm/yy',
    accept: 'Oui',
    reject: 'Non',
    choose: 'Choisir',
    upload: 'Télécharger',
    cancel: 'Annuler',
    pending: 'En attente',
    fileSizeTypes: ['o', 'Ko', 'Mo', 'Go', 'To', 'Po', 'Eo', 'Zo', 'Yo'],
    aria: {
      trueLabel: 'Vrai',
      falseLabel: 'Faux',
      nullLabel: 'Non sélectionné',
      star: '1 étoile',
      stars: '{star} étoiles',
      selectAll: 'Tous les éléments sélectionnés',
      unselectAll: 'Tous les éléments désélectionnés',
      close: 'Fermer',
      previous: 'Précédent',
      next: 'Suivant',
      navigation: 'Navigation',
      scrollTop: 'Défiler vers le haut',
      moveTop: 'Déplacer en haut',
      moveUp: 'Déplacer vers le haut',
      moveDown: 'Déplacer vers le bas',
      moveBottom: 'Déplacer en bas',
      moveToTarget: 'Déplacer vers la cible',
      moveToSource: 'Déplacer vers la source',
      moveAllToTarget: 'Tout déplacer vers la cible',
      moveAllToSource: 'Tout déplacer vers la source',
      pageLabel: 'Page {page}',
      firstPageLabel: 'Première page',
      lastPageLabel: 'Dernière page',
      nextPageLabel: 'Page suivante',
      prevPageLabel: 'Page précédente',
      rowsPerPageLabel: 'Lignes par page',
    },
  },
})

app.directive('tooltip', Tooltip)

app.mount('#app')
