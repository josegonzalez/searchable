<?php
/**
 * Re-builds the Search Index for one, several or all models with Searchable
 * Beahvior attached.
 *
 * @author Neil Crookes
 * @copyright Neil Crookes
 * @link http://www.neilcrookes.com
 */
class BuildSearchIndexShell extends Shell {

/**
 * The tasks that this shell uses. Model for listing models and Db
 *
 * @var array
 */
    var $tasks = array('Model');

/**
 * The selected database configuration.
 *
 * @var string
 */
    protected $_useDbConfig;

/**
 * Array of available models that have the Searchable Behavior attached
 *
 * @var array
 */
    protected $_availableModelnames = array();

/**
 * undocumented class variable
 *
 * @var string
 **/
    public $interactive = true;

/**
 * undocumented class variable
 *
 * @var string
 **/
    public $quiet = false;


/**
 * The main function, executed automatically when running the shell
 *
 */
    function main() {
        parent::loadTasks();

        $this->out('Build Search Index Shell');
        $this->hr();

        // Figure out which db config we are using
        $this->_setDbConfig();

        // Determine the models for the selected db config that have Searchable
        $this->_setAvailableModels();

        // If no args on command line, run interactively
        if (empty($this->args)) {
            $modelNames = $this->_interactive();
        } else { // Pass command line args off to validate input
            $modelNames = $this->_determineModelnames(implode(' ', $this->args));
        }

        // Turn off interactivity where necessary
        if (isset($this->params['interactive']) && $this->params['interactive'] !== "true") {
            $this->interactive = false;
        }

        // Turn off output where necessary
        if (isset($this->params['quiet']) && $this->params['quiet'] == "true") {
            $this->quiet = true;
        }


        foreach ($modelNames as $modelName) {
            if ($this->interactive) {
                // Confirm rebuild index for this model
                $skip = $this->in(__("Are you sure you want to rebuild the search index for $modelName, 'y' or 'n' or 'q' to exit"), null, 'n');

                // Quit if they want to
                if (strtolower($skip) === 'q') {
                    $this->out(__("Exit"));
                    $this->_stop();
                }

                // Skip if they want to
                if (strtolower($skip) !== 'y') {
                    $this->out(__("Skipping " . $modelName));
                    continue;
                }
            }

            // Instantiate the model object
            $ModelObj = ClassRegistry::init($modelName);

            // Delete the records in the search index for the current model
            if (!$ModelObj->deleteSearchIndex(true)) {
                $this->err(__('Could not delete search index'));
                $this->_stop();
            }

            // Find all records
            if (method_exists($ModelObj, 'getAllSearchableData')) {
                $records = $ModelObj->getAllSearchableData();
            } else {
                $records = $ModelObj->find('all', array('recursive' => 0));
            }

            foreach ($records as $record) {
                // Set model->data property
                $ModelObj->set($record);

                // Set the _records property of the Searchable Behavior
                $ModelObj->setSearchableRecords(false, true);

                // Save the search index record
                $ModelObj->saveSearchIndex(true);

                // Report progress
                $this->out($ModelObj->alias . ' ' . $ModelObj->id . ' ' . $record[$ModelObj->alias][$ModelObj->displayField]);
            }
        }

        $this->hr();
    }

/**
 * Outputs a single or multiple messages to stdout. If no parameters
 * are passed outputs just a newline.
 *
 * @param mixed $message A string or a an array of strings to output
 * @param integer $newlines Number of newlines to append
 * @return integer Returns the number of bytes returned from writing to stdout.
 * @access public
 */
    function out($message = null, $newlines = 1) {
        if (!$this->quiet) {
            return parent::out($message, $newlines);
        }
    }

/**
 * Outputs a single or multiple error messages to stderr. If no parameters
 * are passed outputs just a newline.
 *
 * @param mixed $message A string or a an array of strings to output
 * @param integer $newlines Number of newlines to append
 * @access public
 */
    function err($message = null, $newlines = 1) {
        if (!$this->quiet) {
            return parent::err($message, $newlines);
        }
    }

/**
 * Outputs a series of minus characters to the standard output, acts as a visual separator.
 *
 * @param integer $newlines Number of newlines to pre- and append
 * @access public
 */
	function hr($newlines = 0) {
        if (!$this->quiet) {
            return parent::hr($newlines);
        }
    }

/**
 * Sets only or selected db config
 */
    protected function _setDbConfig() {
        if (!empty($this->params['connection'])) {
            $this->_useDbConfig = $this->params['connection'];
            return;
        } else {
            $configs = get_class_vars('DATABASE_CONFIG');
            if (!is_bool($configs)) {
                $configs = array_keys($configs);
                // Prompt if multiple, which db config to use.
                if (count($configs) > 1) {
                    $this->_useDbConfig = $this->in(__('Use Database Config') .':', $configs, 'default');
                } else { // else use the only one
                    $this->_useDbConfig = current($configs);
                }
            }
        }
        if (empty($this->_useDbConfig)) {
            $this->_useDbConfig = 'default';
        }
    }

/**
 * Identifies all models that exist and have Searchable Beahavior attached
 */
    protected function _setAvailableModels() {
        // Initialise paths array with paths to app/models
        $paths = array(MODELS);

        // Get a list of the plugins
        $plugins = Configure::listObjects('plugin');

        // For each plugin, add the plugin model path to paths and instantiate the
        // plugin AppModel in case the plugin contains a model that is Searchable
        // and we need to instantiate it later
        if (!empty($plugins)) {
            foreach ($plugins AS $plugin) {
                $paths[] = APP . 'plugins' . DS . Inflector::underscore($plugin) . DS . 'models' . DS;
                App::import('Model', $plugin . '.' . $plugin . 'Model');
            }
        }

        // Get a list of all the models in all the paths and sort them
        // alphabetically
        $modelNames = Configure::listObjects('model', $paths);
        sort($modelNames);

        // Store those that exist and have Searchable attached
        foreach ($modelNames as $modelName) {
            // Try to import the model
            if (!App::import('Model', $modelName, true, $paths)) {
                continue;
            }

            // Skip abstract classes
            try {
                $testClass = new ReflectionClass($modelName);
                if ($testClass->isAbstract()) {
                    continue;
                }
            } catch (Exception $e) {}

            // Make sure the model extends Model
            if (!is_subclass_of(ClassRegistry::init($modelName), 'Model')) {
                continue;
            }

            // If Searchable not attached, skip
            if (!ClassRegistry::init($modelName)->Behaviors->attached('Searchable')) {
                continue;
            }

            // Store model name in class property
            $this->_availableModelnames[] = $modelName;
        }
    }

/**
 * Returns an array of one, multiple or all model names. Displays available
 * models with Searchable and prompts the user to make a selection
 *
 * @return array
 */
    protected function _interactive() {
        $this->out(__('Possible Models based on your current database:'));

        // List available mode names with numbers for easy selection
        $i = 1;
        foreach ($this->_availableModelnames as $modelName) {
            $this->out($i++ . ". " . $modelName);
        }

        // While the user has not made a selection
        $enteredModel = '';
        while ($enteredModel == '') {
            // Prompt them for a selection
            $enteredModel = $this->in(__("Enter one or more numbers from the list above separated by a space, or type in the names of one or more other models, 'a' for all or 'q' to exit"), null, 'q');

            // Quit if they want to
            if (strtolower($enteredModel) === 'q') {
                $this->out(__("Exit"));
                $this->_stop();
            }
        }

        // Determine the model name from what they entered
        return $this->_determineModelnames($enteredModel);
    }

/**
 * Determines actual model names based on entered model names
 *
 * @param string $enteredModel
 * @return array Array of model names
 */
    protected function _determineModelnames($enteredModel) {
        // If they want all available models return them all
        if (strtolower($enteredModel) == 'a' || strtolower($enteredModel) == 'all') {
            return $this->_availableModelnames;
        }

        // Make an array of the input from the user, by splitting on non
        // alphanumeric characters if multiple
        if (preg_match('/[^a-z0-9]/i', $enteredModel)) {
            $enteredModels = preg_split('/[^a-z0-9]/i', $enteredModel);
        } else { // Else make it an array
            $enteredModels = array($enteredModel);
        }

        // For each value the user entered, check if they entered a number
        // corresponding to one of the options, else assume they entered the name of
        // the model they want, so camelise it and check it's one of the available
        // ones, else, error.
        $selectedModelNames = array();
        foreach ($enteredModels as $enteredModel) {
            // If a valid number was entered, work out the corresponding model name
            if (intval($enteredModel) > 0 && intval($enteredModel) <= count($this->_availableModelnames) ) {
                $selectedModelNames[] = $this->_availableModelnames[intval($enteredModel) - 1];
            // Else, if they entered text that matches an available model name, use that
            } elseif (in_array(Inflector::camelize($enteredModel), $this->_availableModelnames)) {
                $selectedModelNames[] = Inflector::camelize($enteredModel);
            // Else, tell them it didn't work, and send them back to the start
            } else {
                $this->err(__('You entered an invalid model "'.$enteredModel.'". Please try again.'));
                return $this->_interactive();
            }
        }

        return $selectedModelNames;
    }

/**
 * Displays help contents
 */
    public function help() {
        $this->out('CakePHP Build Search Index Shell:');
        $this->hr();
        $this->out('Rebuilds the search index for selected models');
        $this->hr();
        $this->out("Usage: cake build_search_index [all|<arg1> [<arg2>]...]");
        $this->hr();
        $this->out('Arguments:');
        $this->out("\n<no arguments>\n\tInteractive mode e.g. cake build_search_index");
        $this->out("\nall\n\tBuilds search index for all searchable models    e.g. cake build_search_index all");
        $this->out("\n<CamelCased model name>\n\tOne or more CamelCasedModelNames e.g. cake build_search_index Post Category.");
        $this->out("");
    }

}