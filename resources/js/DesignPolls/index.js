// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Design Votes demos: poll slug => option key => live demo component. The polls' text and option keys are in
// App\Support\DesignPolls; every option there needs a component here (DesignVoteTest checks the keys match).

import PickDays from './DeliveryDays/PickDays.vue';
import CycleDays from './DeliveryDays/CycleDays.vue';
import StarDays from './DeliveryDays/StarDays.vue';
import GridDays from './DeliveryDays/GridDays.vue';
import RowsDays from './DeliveryDays/RowsDays.vue';

export default {
	'delivery-days': {
		pick: PickDays,
		cycle: CycleDays,
		star: StarDays,
		grid: GridDays,
		rows: RowsDays,
	},
};
