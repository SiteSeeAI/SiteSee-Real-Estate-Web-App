'use strict';
// Remove obsolete email-link fragments; codes are entered only in the SMS form.
if(location.hash)history.replaceState(null,'',location.pathname+location.search);
