import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
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
    startsWith: 'Commence par',
    contains: 'Contient',
    notContains: 'Ne contient pas',
    endsWith: 'Finit par',
    equals: 'Égal à',
    notEquals: 'Différent de',
    noFilter: 'Aucun filtre',
    lt: 'Inférieur à',
    lte: 'Inférieur ou égal à',
    gt: 'Supérieur à',
    gte: 'Supérieur ou égal à',
    dateIs: 'La date est',
    dateIsNot: "La date n'est pas",
    dateBefore: 'La date est avant',
    dateAfter: 'La date est après',
    clear: 'Effacer',
    apply: 'Appliquer',
    matchAll: 'Correspondre à tous',
    matchAny: "Correspondre à l'un",
    addRule: 'Ajouter une règle',
    removeRule: 'Supprimer la règle',
    accept: 'Oui',
    reject: 'Non',
    choose: 'Choisir',
    upload: 'Télécharger',
    cancel: 'Annuler',
    completed: 'Terminé',
    pending: 'En attente',
    fileSizeTypes: ['o', 'Ko', 'Mo', 'Go', 'To', 'Po', 'Eo', 'Zo', 'Yo'],
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
    chooseYear: "Choisir l'année",
    chooseMonth: 'Choisir le mois',
    chooseDate: 'Choisir la date',
    prevDecade: 'Décennie précédente',
    nextDecade: 'Décennie suivante',
    prevYear: 'Année précédente',
    nextYear: 'Année suivante',
    prevMonth: 'Mois précédent',
    nextMonth: 'Mois suivant',
    prevHour: 'Heure précédente',
    nextHour: 'Heure suivante',
    prevMinute: 'Minute précédente',
    nextMinute: 'Minute suivante',
    prevSecond: 'Seconde précédente',
    nextSecond: 'Seconde suivante',
    am: 'am',
    pm: 'pm',
    today: "Aujourd'hui",
    weekHeader: 'Sem',
    firstDayOfWeek: 1,
    showMonthAfterYear: false,
    dateFormat: 'dd/mm/yy',
    weak: 'Faible',
    medium: 'Moyen',
    strong: 'Fort',
    passwordPrompt: 'Entrez un mot de passe',
    emptyFilterMessage: 'Aucun résultat trouvé',
    searchMessage: '{0} résultats disponibles',
    selectionMessage: '{0} éléments sélectionnés',
    emptySelectionMessage: 'Aucun élément sélectionné',
    emptySearchMessage: 'Aucun résultat trouvé',
    fileChosenMessage: '{0} fichiers',
    noFileChosenMessage: 'Aucun fichier choisi',
    emptyMessage: 'Aucune option disponible',
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

app.use(ConfirmationService)
app.use(ToastService)
app.directive('tooltip', Tooltip)

// Global error handler to prevent unhandled errors from crashing the entire Vue app
app.config.errorHandler = (err, _instance, info) => {
  console.error(`[Vue Error] ${info}:`, err)
}

app.mount('#app')
