import { createContext } from 'react';

/**
 * { selectedChild: {id, child_name, year_group} | null,
 *   setSelectedChild: fn }
 */
const SelectedChildContext = createContext({
  selectedChild: null,
  setSelectedChild: () => {},
});

export default SelectedChildContext;
