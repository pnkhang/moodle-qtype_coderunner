<?php

/*
 * Provides a NullSandbox class, which is a sandbox in name only -- it
 * doesn't provide any security features, but just implements the generic
 * Sandbox interface by running the code unsecured in a temporary subdirectory.
 * Intended for testing or for providing (unsafe) support for languages that
 * won't run in any of the standard sandboxes.
 *
 * VERY LITTLE TESTING -- for emergency use only.
 *
 * @package    qtype
 * @subpackage coderunner
 * @copyright  2012 Richard Lobb, University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('localsandbox.php');

define('MAX_READ', 4096);  // Max bytes to read in popen

// ==============================================================
//
// Language definitions.
//
// ==============================================================
class Matlab_ns_Task extends LanguageTask {
    public function __construct($source) {
        LanguageTask::__construct($source);
    }

    public function getVersion() {
        return 'Matlab R2012';
    }

    public function compile() {
        $this->executableFileName = $this->sourceFileName . '.m';
        if (!copy($this->sourceFileName, $this->executableFileName)) {
            throw new coding_exception("Matlab_Task: couldn't copy source file");
        }
    }

    public function readableDirs() {
        return array('/');  // Not meaningful in this sandbox
     }

     public function getRunCommand() {
         return array(
             dirname(__FILE__)  . "/runguard",
             "--user=coderunner",
             "--time=5",             // Seconds of execution time allowed
             "--memsize=2000000",    // Max kb mem allowed (2GB!)
             "--filesize=10000",     // Max file sizes (10MB)
             "--nproc=10",           // At most 10 processes/threads
             "--no-core",
             "--streamsize=10000",   // Max stdout/stderr sizes (10MB)
             '/usr/local/Matlab2012a/bin/glnxa64/MATLAB',
             '-nojvm',
             '-nodesktop',
             '-singleCompThread',
             '-r',
             basename($this->sourceFileName)
         );
     }


     public function filterOutput($out) {
         $lines = explode("\n", $out);
         $outlines = array();
         $headerEnded = FALSE;
         foreach ($lines as $line) {
             $line = trim($line);
             if ($headerEnded && $line != '') {
                 $outlines[] = $line;
             }
             if (strpos($line, 'For product information, visit www.mathworks.com.') !== FALSE) {
                 $headerEnded = TRUE;
             }
         }
         return implode("\n", $outlines);
     }
};

class Python2_ns_Task extends LanguageTask {
    public function __construct($source) {
        LanguageTask::__construct($source);
    }

    public function getVersion() {
        return 'Python 2.7';
    }

    public function compile() {
        $this->executableFileName = $this->sourceFileName;
    }

    public function readableDirs() {
        return array();  // Irrelevant for this sandbox
     }

     // Return the command to pass to localrunner as a list of arguments,
     // starting with the program to run followed by a list of its arguments.
     public function getRunCommand() {
        return array(
             dirname(__FILE__)  . "/runguard",
             "--user=coderunner",
             "--time=3",             // Seconds of execution time allowed
             "--memsize=100000",     // Max kb mem allowed (100MB)
             "--filesize=10000",     // Max file sizes (10MB)
             "--nproc=2",            // At most 2 processes/threads
             "--no-core",
             "--streamsize=10000",   // Max stdout/stderr sizes (10MB)
             '/usr/bin/python2',
             $this->sourceFileName
         );
     }
};

class Java_ns_Task extends LanguageTask {
    public function __construct($source) {
        LanguageTask::__construct($source);
    }

    public function getVersion() {
        return 'Java 1.6';
    }

    public function compile() {
        $prog = file_get_contents($this->sourceFileName);
        if (($this->mainClassName = $this->getMainClass($prog)) === FALSE) {
            $this->cmpinfo = "Error: no main class found, or multiple main classes. [Did you write a public class when asked for a non-public one?]";
        }
        else {
            exec("mv {$this->sourceFileName} {$this->mainClassName}.java", $output, $returnVar);
            if ($returnVar !== 0) {
                throw new coding_exception("Java compile: couldn't rename source file");
            }
            $this->sourceFileName = "{$this->mainClassName}.java";
            exec("/usr/bin/javac {$this->sourceFileName} 2>compile.out", $output, $returnVar);
            if ($returnVar == 0) {
                $this->cmpinfo = '';
                $this->executableFileName = $this->sourceFileName;
            }
            else {
                $this->cmpinfo = file_get_contents('compile.out');
            }
        }
    }


    public function readableDirs() {
        return array(
            '/'  // Irrelevant in the null sandbox
        );
     }

     public function getRunCommand() {
         return array(
             dirname(__FILE__)  . "/runguard",
             "--user=coderunner",
             "--time=5",              // Seconds of execution time allowed
             "--memsize=2000000",     // Max kb mem allowed (2GB Why does it need so much?)
             "--filesize=10000",      // Max file sizes (10MB)
             "--nproc=20",            // At most 20 processes/threads (why so many needed??)
             "--no-core",
             "--streamsize=10000",    // Max stdout/stderr sizes (10MB)
             '/usr/bin/java',
             "-Xrs",   //  reduces usage signals by java, because that generates debug
                       //  output when program is terminated on timelimit exceeded.
             "-Xss8m",
             "-Xmx200m",
             $this->mainClassName
         );
     }


     // Return the name of the main class in the given prog, or FALSE if no
     // such class found. Uses a regular expression to find a public class with
     // a public static void main method.
     // Not totally safe as it doesn't parse the file, e.g. would be fooled
     // by a commented-out main class with a different name.
     private function getMainClass($prog) {
         $pattern = '/(^|\W)public\s+class\s+(\w+)\s*\{.*?public\s+static\s+void\s+main\s*\(\s*String/ms';
         if (preg_match_all($pattern, $prog, $matches) !== 1) {
             return FALSE;
         }
         else {
             return $matches[2][0];
         }
     }
};

// ==============================================================
//
// Now the actual null sandbox.
//
// ==============================================================

class NullSandbox extends LocalSandbox {

    public function __construct($user=NULL, $pass=NULL) {
        LocalSandbox::__construct($user, $pass);
    }

    public function getLanguages() {
        return (object) array(
            'error' => Sandbox::OK,
            'languages' => array('matlab', 'python2', 'Java')
        );
    }


    protected function createTask($language, $source) {
        $reqdClass = ucwords($language) . "_ns_Task";
        return new $reqdClass($source);
    }


    // Run the current $this->task in the (nonexistent) sandbox,
    // i.e. it runs it on the current machine, albeit with resource
    // limits like maxmemory, maxnumprocesses and maxtime set.
    // Results are all left in $this->task for later access by
    // getSubmissionDetails
    protected function runInSandbox($input) {
        $cmd = implode(' ', $this->task->getRunCommand()) . ">prog.out 2>prog.err";
        $workdir = $this->task->workdir;
        chdir($workdir);
        try {
            $this->task->cmpinfo = ''; // Set defaults first
            $this->task->signal = 0;
            $this->task->time = 0;
            $this->task->memory = 0;

            if ($input != '') {
                $f = fopen('prog.in', 'w');
                fwrite($f, $input);
                fclose($f);
                $cmd .= " <prog.in";
            }

            $handle = popen($cmd, 'r');
            $result = fread($handle, MAX_READ);
            pclose($handle);

            if (file_exists("$workdir/prog.err")) {
                $this->task->stderr = file_get_contents("$workdir/prog.err");
            }
            else {
                $this->task->stderr = '';
            }
            if ($this->task->stderr != '') {
                if (strpos($this->task->stderr, "warning: timelimit exceeded")) {
                    $this->task->result = Sandbox::RESULT_TIME_LIMIT;
                    $this->task->signal = 9;
                    $this->task->stderr = '';
                } else {
                    $this->task->result = Sandbox::RESULT_ABNORMAL_TERMINATION;
                }
            }
            else {
                $this->task->result = Sandbox::RESULT_SUCCESS;
            }

            $this->task->output = $this->task->filterOutput(
                    file_get_contents("$workdir/prog.out"));
        }
        catch (Exception $e) {
            $this->task->result = Sandbox::RESULT_INTERNAL_ERR;
            $this->task->stderr = $this->task->cmpinfo = print_r($e, true);
            $this->task->output = $this->task->stderr;
            $this->task->signal = $this->task->time = $this->task->memory = 0;
        }
    }
}
?>