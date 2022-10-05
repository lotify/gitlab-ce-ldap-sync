<?php

namespace AdamReece\GitlabCeLdapSync;

require_once('Config.php');


use Exception;
use Gitlab\Client;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;

use Cocur\Slugify\Slugify;
use UnexpectedValueException;

class LdapSyncCommand extends Command
{
    /*
     * -------------------------------------------------------------------------
     * Constants
     * -------------------------------------------------------------------------
     */

    const CONFIG_FILE_NAME = "config.yml";
    const CONFIG_FILE_DIST_NAME = "config.yml.dist";

    const API_COOL_DOWN_USECONDS = 100000;


    /*
     * -------------------------------------------------------------------------
     * Variables
     * -------------------------------------------------------------------------
     */

    /**
     * @var ConsoleLogger Console logger interface
     */
    private $logger = null;

    /**
     * @var bool Debug mode
     */
    private $dryRun = false;

    /**
     * @var bool Continue on failure: Do not abort on certain errors
     */
    private $continueOnFail = false;

    /**
     * @var array User Configuration/
     */
    private $config = [];
    /*
     * -------------------------------------------------------------------------
     * Command functions
     * -------------------------------------------------------------------------
     */

    /**
     * Configures the current command.
     * @return void
     */
    public function configure(): void
    {
        $this
            ->setName("ldap:sync")
            ->setDescription("Sync LDAP users and groups with a Gitlab CE/EE self-hosted installation.")
            ->addOption("dryrun", "d", InputOption::VALUE_NONE, "Dry run: Do not persist any changes.")
            ->addOption(
                "continueOnFail",
                null,
                InputOption::VALUE_NONE,
                "Do not abort on certain errors. (Continue running if possible.)"
            )
            ->addArgument(
                "instance",
                InputArgument::OPTIONAL,
                "Sync with a specific instance, or leave unspecified to work with all."
            );
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  Input interface
     * @param OutputInterface $output Output interface
     *
     * @return int|null                Error code, or null/zero for success
     */
    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->logger = new ConsoleLogger($output);
        $output->writeln("LDAP users and groups sync script for Gitlab-CE\n");

        // Prepare
        if ($this->dryRun = boolval($input->getOption("dryrun"))) {
            $this->logger->warning("Dry run enabled: No changes will be persisted.");
        }

        if ($this->continueOnFail = boolval($input->getOption("continueOnFail"))) {
            $this->logger->warning("Continue on failure enabled: Certain errors will be ignored if possible.");
        }

        $rootDir = sprintf("%s/../", __DIR__);
        $configFilePathname = sprintf("%s/%s", $rootDir, self::CONFIG_FILE_NAME);
        $configFileDistPathname = sprintf("%s/%s", $rootDir, self::CONFIG_FILE_DIST_NAME);

        foreach ([
                     "ldap_connect",
                     "ldap_bind",
                     "ldap_set_option",
                     "ldap_errno",
                     "ldap_error",
                     "ldap_search",
                     "ldap_get_entries",
                 ] as $ldapFunction) {
            if (!function_exists($ldapFunction)) {
                $this->logger->critical(sprintf("PHP-LDAP function \"%s\" does not exist.", $ldapFunction));

                return 1;
            }
        }


        // Load configuration

        $Config = new Config($this->logger);
        $this->logger->notice("Loading configuration.", ["file" => $configFilePathname]);

        if (!($this->config = $Config->loadConfig($configFilePathname))) {
            $this->logger->debug(
                "Checking if default configuration exists but user configuration does not.",
                ["file" => $configFileDistPathname]
            );
            if (file_exists($configFileDistPathname) && !file_exists($configFilePathname)) {
                $this->logger->warning("Dist config found but user config not.");
                $output->writeln(
                    sprintf(
                        "It appears that you have not created a configuration yet.\nPlease duplicate \"%s\" as \"%s\", then modify it for your\nenvironment.",
                        self::CONFIG_FILE_DIST_NAME,
                        self::CONFIG_FILE_NAME
                    )
                );
            }

            return 1;
        }

        $this->logger->notice("Loaded configuration.");

        // Validate configuration
        $this->logger->notice("Validating configuration.");

        $configProblems = [];
        if (!$Config->validateConfig($this->config, $configProblems)) {
            $this->logger->error(
                sprintf("%d configuration problem(s) need to be resolved.", count($configProblems["error"]))
            );

            return 1;
        }
        $this->logger->notice("Validated configuration.");


        // Retrieve groups from LDAP
        $this->logger->notice("Retrieving directory users and groups.");

        $ldapUsers = [];
        $ldapUsersNum = 0;
        $ldapGroups = [];
        $ldapGroupsNum = 0;

        try {
            $this->getLdapUsersAndGroups($ldapUsers, $ldapUsersNum, $ldapGroups, $ldapGroupsNum);
        } catch (Exception $e) {
            $this->logger->error(sprintf("LDAP failure: %s", $e->getMessage()), ["error" => $e]);

            return 1;
        }

        $this->logger->notice("Retrieved directory users and groups.");

        // Deploy to Gitlab instances
        $this->logger->notice("Deploying users and groups to Gitlab instances.");

        $gitlabInstanceOnly = trim(strval($input->getArgument("instance")));
        foreach ($this->config["gitlab"]["instances"] as $gitlabInstance => $gitlabConfig) {
            if ($gitlabInstanceOnly && $gitlabInstance !== $gitlabInstanceOnly) {
                $this->logger->debug(
                    sprintf("Skipping instance \"%s\", doesn't match the argument specified.", $gitlabInstance)
                );
                continue;
            }

            try {
                $this->deployGitlabUsersAndGroups(
                    $gitlabInstance,
                    $gitlabConfig,
                    $ldapUsers,
                    $ldapGroups
                );
            } catch (Exception $e) {
                $this->logger->error(sprintf("Gitlab failure: %s", $e->getMessage()), ["error" => $e]);

                return 1;
            }
        }

        $this->logger->notice("Deployed users and groups to Gitlab instances.");


        // Finished
        return 0;
    }



    /*
     * -------------------------------------------------------------------------
     * Helper functions
     * -------------------------------------------------------------------------
     */


    /**
     * Get users and groups from LDAP.
     *
     * @param array<string,array> $users     Users output
     * @param int                 $usersNum  Users count output
     * @param array<string,array> $groups    Groups output
     * @param int                 $groupsNum Groups count output
     *
     * @return void                           Success if returned, exception thrown on error
     */
    private function getLdapUsersAndGroups(
        array &$users,
        int &$usersNum,
        array &$groups,
        int &$groupsNum
    ): void {
        $slugifyLdapUsername = new Slugify([
            "regexp" => "/([^A-Za-z0-9-_\.])+/",
            "separator" => ",",
            "lowercase" => false,
            "trim" => true,
        ]);

        // Connect
        $this->logger->notice(
            "Establishing LDAP connection: \n\thost: {host}\n\tport: {port}\n\tversion: {version}\n\tencryption: {encryption}\n\tbindDn: {bindDn}",
            [
                "host" => $this->config["ldap"]["server"]["host"],
                "port" => $this->config["ldap"]["server"]["port"],
                "version" => $this->config["ldap"]["server"]["version"],
                "encryption" => $this->config["ldap"]["server"]["encryption"],
                "bindDn" => $this->config["ldap"]["server"]["bindDn"],
            ]
        );

        $ldapUri = sprintf(
            "ldap%s://%s:%d/",
            "ssl" === $this->config["ldap"]["server"]["encryption"] ? "s" : "",
            $this->config["ldap"]["server"]["host"],
            $this->config["ldap"]["server"]["port"]
        );

        if ($this->config["ldap"]["debug"]) {
            $this->logger->debug("LDAP: Enabling debug mode");
            @ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 6);
        }

        // Solves: ldap_search(): Search: Operations error.
        // Occurs when no "user_dn" has been specified.
        // https://stackoverflow.com/questions/17742751/ldap-operations-error
        if ($this->config["ldap"]["winCompatibilityMode"]) {
            $this->logger->debug("LDAP: Enabling compatibility mode");
            @ldap_set_option(null, LDAP_OPT_REFERRALS, 0);
        }

        $this->logger->debug("LDAP: Connecting to {uri}", ["uri" => $ldapUri]);
        if (false === ($ldap = @ldap_connect($ldapUri))) {
            throw new RuntimeException(
                sprintf(
                    "LDAP connection will not be possible. Check that your server address and port \"%s\" are plausible.",
                    $ldapUri
                )
            );
        }

        $this->logger->debug("LDAP: Setting options");
        if (false === @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, $this->config["ldap"]["server"]["version"])) {
            throw new RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        if ("tls" === $this->config["ldap"]["server"]["encryption"]) {
            $this->logger->debug("LDAP: STARTTLS");
            if (false === @ldap_start_tls($ldap)) {
                throw new RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
            }
        }

        $this->logger->debug("LDAP: Binding dn: {dn}", ["dn" => $this->config["ldap"]["server"]["bindDn"]]);
        if (false === @ldap_bind(
                $ldap,
                $this->config["ldap"]["server"]["bindDn"],
                $this->config["ldap"]["server"]["bindPassword"]
            )) {
            throw new RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $this->logger->notice("LDAP connection established.");

        // << Retrieve users
        $ldapUsersQueryBase = sprintf(
            "%s%s%s",
            $this->config["ldap"]["queries"]["userDn"],
            strlen($this->config["ldap"]["queries"]["userDn"]) >= 1 ? "," : "",
            $this->config["ldap"]["queries"]["baseDn"]
        );

        $ldapUsersQueryAttributes = [
            $this->config["ldap"]["queries"]["userUniqueAttribute"],
            $this->config["ldap"]["queries"]["userMatchAttribute"],
            $this->config["ldap"]["queries"]["userNameAttribute"],
            $this->config["ldap"]["queries"]["userEmailAttribute"],
            $this->config["ldap"]["queries"]["userLdapAdminAttribute"],
            $this->config["ldap"]["queries"]["userSshKeyAttribute"],
        ];

        $this->logger->debug("Retrieving users with: \n\tbase: {base}\n\tfilter: {filter}\n\tattribute: {attribute}", [
            "base" => $ldapUsersQueryBase,
            "filter" => $this->config["ldap"]["queries"]["userFilter"],
            "attributes" => $ldapUsersQueryAttributes,
        ]);
        if (false === ($ldapUsersQuery = @ldap_search(
                $ldap,
                $ldapUsersQueryBase,
                $this->config["ldap"]["queries"]["userFilter"],
                $ldapUsersQueryAttributes
            ))) {
            throw new RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $ldapUserAttribute = strtolower($this->config["ldap"]["queries"]["userUniqueAttribute"]);
        $ldapUserMatchAttribute = strtolower($this->config["ldap"]["queries"]["userMatchAttribute"]);
        $ldapNameAttribute = strtolower($this->config["ldap"]["queries"]["userNameAttribute"]);
        $ldapEmailAttribute = strtolower($this->config["ldap"]["queries"]["userEmailAttribute"]);
        $ldapAdminAttribute = strtolower($this->config["ldap"]["queries"]["userLdapAdminAttribute"]);
        $ldapSshKeyAttribute = strtolower($this->config["ldap"]["queries"]["userSshKeyAttribute"]);

        if (is_array($ldapUsers = @ldap_get_entries($ldap, $ldapUsersQuery)) && is_iterable($ldapUsers)) {
            if (($ldapUsersNum = count($ldapUsers)) >= 1) {
                $this->logger->notice("{userCount} directory user(s) found.", ["userCount" => $ldapUsersNum]);
                foreach ($ldapUsers as $i => $ldapUser) {
                    if (!is_int($i)) {
                        continue;
                    }
                    $n = $i + 1;

//                    $this->logger->debug(sprintf("User: %s", print_r($ldapUser, 1)));
                    if (!is_array($ldapUser)) {
                        $this->logger->error(sprintf("User #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($ldapUser["dn"]) || !is_string($ldapUser["dn"])) {
                        $this->logger->error(sprintf("User #%d: Missing distinguished name.", $n));
                        continue;
                    }

                    if (!($ldapUserDn = trim($ldapUser["dn"]))) {
                        $this->logger->error(sprintf("User #%d: Empty distinguished name.", $n));
                        continue;
                    }

                    if (!isset($ldapUser[$ldapUserAttribute])) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapUserAttribute)
                        );
                        continue;
                    }

                    if (!is_array(
                            $ldapUser[$ldapUserAttribute]
                        ) || !isset($ldapUser[$ldapUserAttribute][0]) || !is_string($ldapUser[$ldapUserAttribute][0])) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapUserAttribute)
                        );
                        continue;
                    }

                    if (!($ldapUserName = trim($ldapUser[$ldapUserAttribute][0]))) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapUserAttribute)
                        );
                        continue;
                    }

                    // Make sure the username format is compatible with Gitlab later on
                    if (($ldapUserNameSlugified = $slugifyLdapUsername->slugify($ldapUserName)) !== $ldapUserName) {
                        $this->logger->warning(
                            sprintf(
                                "User #%d [%s]: Username \"%s\" is incompatible with Gitlab, changed to \"%s\".",
                                $n,
                                $ldapUserDn,
                                $ldapUserName,
                                $ldapUserNameSlugified
                            )
                        );
                        $ldapUserName = $ldapUserNameSlugified;
                    }

                    if (!isset($ldapUser[$ldapUserMatchAttribute])) {
                        $this->logger->error(
                            sprintf(
                                "User #%d [%s]: Missing attribute \"%s\".",
                                $n,
                                $ldapUserDn,
                                $ldapUserMatchAttribute
                            )
                        );
                        continue;
                    }

                    if (!is_array(
                            $ldapUser[$ldapUserMatchAttribute]
                        ) || !isset($ldapUser[$ldapUserMatchAttribute][0]) || !is_string(
                            $ldapUser[$ldapUserMatchAttribute][0]
                        )) {
                        $this->logger->error(
                            sprintf(
                                "User #%d [%s]: Invalid attribute \"%s\".",
                                $n,
                                $ldapUserDn,
                                $ldapUserMatchAttribute
                            )
                        );
                        continue;
                    }

                    if (!($ldapUserMatch = trim($ldapUser[$ldapUserMatchAttribute][0]))) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapUserMatchAttribute)
                        );
                        continue;
                    }

                    if (!isset($ldapUser[$ldapNameAttribute])) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapNameAttribute)
                        );
                        continue;
                    }

                    if (!is_array(
                            $ldapUser[$ldapNameAttribute]
                        ) || !isset($ldapUser[$ldapNameAttribute][0]) || !is_string($ldapUser[$ldapNameAttribute][0])) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapNameAttribute)
                        );
                        continue;
                    }

                    if (!($ldapUserFullName = trim($ldapUser[$ldapNameAttribute][0]))) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapNameAttribute)
                        );
                        continue;
                    }

                    if (!isset($ldapUser[$ldapEmailAttribute])) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapEmailAttribute)
                        );
                        continue;
                    }

                    if (!is_array(
                            $ldapUser[$ldapEmailAttribute]
                        ) || !isset($ldapUser[$ldapEmailAttribute][0]) || !is_string(
                            $ldapUser[$ldapEmailAttribute][0]
                        )) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapEmailAttribute)
                        );
                        continue;
                    }

                    if (!($ldapUserEmail = trim($ldapUser[$ldapEmailAttribute][0]))) {
                        $this->logger->error(
                            sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapEmailAttribute)
                        );
                        continue;
                    }

                    $ldapUserSshKeys = null;
                    if ($ldapSshKeyAttribute && isset($ldapUser[$ldapSshKeyAttribute])) {

                        $ldapUserSshKeys = [];
                        foreach ($ldapUser[$ldapSshKeyAttribute] as $key) {
                            if (substr($key, 0, 8) != 'ssh-rsa ') {
                                continue;
                            }
                            $fingerprint = $this->getFingerprint($key);
                            $ldapUserSshKeys[] = ["key" => $key, "fingerprint" => $fingerprint];
                        }
                    }
                    if ($this->in_array_i($ldapUserName, $this->config["gitlab"]["options"]["userNamesToIgnore"])) {
                        $this->logger->info(sprintf("User \"%s\" in ignore list.", $ldapUserName));
                        continue;
                    }

                    $this->logger->info(sprintf("Found directory user \"%s\" [%s].", $ldapUserName, $ldapUserDn));

                    if (isset($users[$ldapUserName]) && is_array($users[$ldapUserName])) {
                        $this->logger->warning(
                            sprintf("Duplicate directory user \"%s\" [%s].", $ldapUserName, $ldapUserDn)
                        );
                        continue;
                    }

                    $ldapAdmin = false;
                    if (isset($ldapUser[$ldapAdminAttribute])) {
                        $ldapAdmin = boolval(trim($ldapUser[$ldapAdminAttribute][0]));
                    }
                    $users[$ldapUserName] = [
                        "dn" => $ldapUserDn,
                        "username" => $ldapUserName,
                        "userMatchId" => $ldapUserMatch,
                        "fullName" => $ldapUserFullName,
                        "email" => $ldapUserEmail,
                        "isAdmin" => $ldapAdmin,
                        "isExternal" => false,
                        "sshKeys" => $ldapUserSshKeys,
                    ];
                }
                ksort($users);
                $this->logger->notice(sprintf("%d directory user(s) recognised.", $usersNum = count($users)));
            } else {
                $this->logger->warning("No directory users found.");
            }
        } else {
            $this->logger->error("Directory users query failed.");
        }
        // >> Retrieve users
        // << Retrieve groups
        $ldapGroupsQueryBase = sprintf(
            "%s%s%s",
            $this->config["ldap"]["queries"]["groupDn"],
            strlen($this->config["ldap"]["queries"]["groupDn"]) >= 1 ? "," : "",
            $this->config["ldap"]["queries"]["baseDn"]
        );

        $ldapGroupsQueryAttributes = [
            $this->config["ldap"]["queries"]["groupUniqueAttribute"],
            $this->config["ldap"]["queries"]["groupMemberAttribute"],
        ];

        $this->logger->debug("Retrieving groups.", [
            "base" => $ldapGroupsQueryBase,
            "filter" => $this->config["ldap"]["queries"]["groupFilter"],
            "attributes" => $ldapGroupsQueryAttributes,
        ]);

        if (false === ($ldapGroupsQuery = @ldap_search(
                $ldap,
                $ldapGroupsQueryBase,
                $this->config["ldap"]["queries"]["groupFilter"],
                $ldapGroupsQueryAttributes
            ))) {
            throw new RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $ldapGroupAttribute = strtolower($this->config["ldap"]["queries"]["groupUniqueAttribute"]);
        $ldapGroupMemberAttribute = strtolower($this->config["ldap"]["queries"]["groupMemberAttribute"]);

        if (is_array($ldapGroups = @ldap_get_entries($ldap, $ldapGroupsQuery)) && is_iterable($ldapGroups)) {
            if (($ldapGroupsNum = count($ldapGroups)) >= 1) {
                $this->logger->notice(sprintf("%d directory group(s) found.", $ldapGroupsNum));

                foreach ($ldapGroups as $i => $ldapGroup) {
                    if (!is_int($i)) {
                        continue;
                    }
                    $n = $i + 1;

                    $this->logger->debug(sprintf("LDAP group: %s", print_r($ldapGroup, 1)));
                    if (!is_array($ldapGroup)) {
                        $this->logger->error(sprintf("Group #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($ldapGroup[$ldapGroupAttribute])) {
                        $this->logger->error(sprintf("Group #%d: Missing attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if (!is_array($ldapGroup[$ldapGroupAttribute]) || !isset($ldapGroup[$ldapGroupAttribute][0])) {
                        $this->logger->error(sprintf("Group #%d: Invalid attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if (!($ldapGroupName = trim($ldapGroup[$ldapGroupAttribute][0]))) {
                        $this->logger->error(sprintf("Group #%d: Empty attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if ($this->in_array_i($ldapGroupName, $this->config["gitlab"]["options"]["groupNamesToIgnore"])) {
                        $this->logger->info(sprintf("Group \"%s\" in ignore list.", $ldapGroupName));
                        continue;
                    }

                    $this->logger->info(sprintf("Found directory group \"%s\".", $ldapGroupName));
                    if (isset($groups[$ldapGroupName])) {
                        $this->logger->warning(sprintf("Duplicate directory group \"%s\".", $ldapGroupName));
                        continue;
                    }

                    $groups[$ldapGroupName] = [];

                    if (!isset($ldapGroup[$ldapGroupMemberAttribute])) {
                        $this->logger->warning(
                            sprintf(
                                "Group #%d: Missing attribute \"%s\". (Could also mean this group has no members.)",
                                $n,
                                $ldapGroupMemberAttribute
                            )
                        );
                        continue;
                    }

                    if (!is_array($ldapGroup[$ldapGroupMemberAttribute])) {
                        $this->logger->warning(
                            sprintf("Group #%d: Invalid attribute \"%s\".", $n, $ldapGroupMemberAttribute)
                        );
                        continue;
                    }

                    if ($groupMembersAreAdmin = $this->in_array_i(
                        $ldapGroupName,
                        $this->config["gitlab"]["options"]["groupNamesOfAdministrators"]
                    )) {
                        $this->logger->info(sprintf("Group \"%s\" members are administrators.", $ldapGroupName));
                    }

                    if ($groupMembersAreExternal = $this->in_array_i(
                        $ldapGroupName,
                        $this->config["gitlab"]["options"]["groupNamesOfExternal"]
                    )) {
                        $this->logger->info(sprintf("Group \"%s\" members are external.", $ldapGroupName));
                    }

                    // Retrieve group user memberships
                    foreach ($ldapGroup[$ldapGroupMemberAttribute] as $j => $ldapGroupMember) {
                        if (!is_int($j)) {
                            continue;
                        }
                        $o = $j + 1;

                        if (!is_string($ldapGroupMember)) {
                            $this->logger->warning(
                                sprintf(
                                    "Group #%d / member #%d: Invalid member attribute \"%s\".",
                                    $n,
                                    $o,
                                    $ldapGroupMemberAttribute
                                )
                            );
                            continue;
                        }

                        if (!($ldapGroupMemberName = trim($ldapGroupMember))) {
                            $this->logger->warning(
                                sprintf(
                                    "Group #%d / member #%d: Empty member attribute \"%s\".",
                                    $n,
                                    $o,
                                    $ldapGroupMemberAttribute
                                )
                            );
                            continue;
                        }

                        $ldapUserMatchFound = false;
                        if ($this->in_array_i($ldapGroupMemberAttribute, ["memberUid"])) {
                            foreach ($users as $userName => $user) {
                                if (($ldapUserMatchAttribute === $ldapUserAttribute ? $userName : $user["userMatchId"]) == $ldapGroupMemberName) {
                                    $ldapGroupMemberName = $userName;
                                    $this->logger->debug(
                                        sprintf(
                                            "Group #%d / member #%d: User ID \"%s\" matched to user name \"%s\".",
                                            $n,
                                            $o,
                                            $user["userMatchId"],
                                            $userName
                                        )
                                    );
                                    $ldapUserMatchFound = true;
                                    break;
                                }
                            }
                        } elseif ($this->in_array_i($ldapGroupMemberAttribute, ["member", "uniqueMember"])) {
                            foreach ($users as $userName => $user) {
                                if ($user["dn"] == $ldapGroupMemberName) {
                                    $ldapGroupMemberName = $userName;
                                    $this->logger->debug(
                                        sprintf(
                                            "Group #%d / member #%d: User ID \"%s\" matched to user name \"%s\".",
                                            $n,
                                            $o,
                                            $user["dn"],
                                            $userName
                                        )
                                    );
                                    $ldapUserMatchFound = true;
                                    break;
                                }
                            }
                        }

                        if (!$ldapUserMatchFound) {
                            $this->logger->warning(
                                sprintf(
                                    "Group #%d / member #%d: No matching user name found for group member attribute \"%s\".",
                                    $n,
                                    $o,
                                    $ldapGroupMemberAttribute
                                )
                            );
                            continue;
                        }

                        if ($this->in_array_i(
                            $ldapGroupMemberName,
                            $this->config["gitlab"]["options"]["userNamesToIgnore"]
                        )) {
                            $this->logger->info(
                                sprintf(
                                    "Group #%d / member #%d: User \"%s\" in ignore list.",
                                    $n,
                                    $o,
                                    $ldapGroupMemberName
                                )
                            );
                            continue;
                        }

                        if (!isset($users[$ldapGroupMemberName]) || !is_array($users[$ldapGroupMemberName])) {
                            $this->logger->warning(
                                sprintf("Group #%d / member #%d: User not found \"%s\".", $n, $o, $ldapGroupMemberName)
                            );
                            continue;
                        }

                        $this->logger->info(
                            sprintf("Found directory group \"%s\" member \"%s\".", $ldapGroupName, $ldapGroupMemberName)
                        );
                        if (isset($groups[$ldapGroupName][$ldapGroupMemberName])) {
                            $this->logger->warning(
                                sprintf(
                                    "Duplicate directory group \"%s\" member \"%s\".",
                                    $ldapGroupName,
                                    $ldapGroupMemberName
                                )
                            );
                            continue;
                        }

                        $groups[$ldapGroupName][] = $ldapGroupMemberName;

                        if ($groupMembersAreAdmin) {
                            $this->logger->info(
                                sprintf(
                                    "Group #%d / member #%d: User \"%s\" is an administrator.",
                                    $n,
                                    $o,
                                    $ldapGroupMemberName
                                )
                            );
                            $users[$ldapGroupMemberName]["isAdmin"] = true;
                        }

                        if ($groupMembersAreExternal) {
                            $this->logger->info(
                                sprintf(
                                    "Group #%d / member #%d: User \"%s\" is external.",
                                    $n,
                                    $o,
                                    $ldapGroupMemberName
                                )
                            );
                            $users[$ldapGroupMemberName]["isExternal"] = true;
                        }
                    }

                    $this->logger->notice(
                        sprintf(
                            "%d directory group \"%s\" member(s) recognised.",
                            count($groups[$ldapGroupName]),
                            $ldapGroupName
                        )
                    );
                    sort($groups[$ldapGroupName]);
                }

                ksort($groups);
                $this->logger->notice(sprintf("%d directory group(s) recognised.", $groupsNum = count($groups)));
            } else {
                $this->logger->warning("No directory groups found.");
            }
        } else {
            $this->logger->error("Directory groups query failed.");
        }
        // >> Retrieve groups

        // Disconnect
        $this->logger->debug("LDAP: Unbinding");
        if (false === @ldap_unbind($ldap)) {
            throw new RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }
        $ldap = null;

        $this->logger->notice("LDAP connection closed.");
    }

    /**
     * Deploy users and groups to a Gitlab instance.
     *
     * @param string              $gitlabInstance Gitlab instance name
     * @param array               $gitlabConfig   Gitlab instance configuration
     * @param array<string,array> $ldapUsers      LDAP users
     * @param array<string,array> $ldapGroups     LDAP groups
     *
     * @return void                                Success if returned, exception thrown on error
     * @throws Exception
     */
    private function deployGitlabUsersAndGroups(
        string $gitlabInstance,
        array $gitlabConfig,
        array $ldapUsers,
        array $ldapGroups
    ): void {
        $slugifyGitlabName = new Slugify([
            "regexp" => "/([^A-Za-z0-9]|-_\. )+/",
            "separator" => " ",
            "lowercase" => false,
            "trim" => true,
        ]);

        $slugifyGitlabPath = new Slugify([
            "regexp" => "/([^A-Za-z0-9]|-_\.)+/",
            "separator" => "-",
            "lowercase" => true,
            "trim" => true,
        ]);

        // Convert LDAP group names into a format safe for Gitlab's restrictions
//        $ldapGroupsSafe = [];
//        foreach ($ldapGroups as $ldapGroupName => $ldapGroupMembers) {
//            $ldapGroupsSafe[$slugifyGitlabName->slugify($ldapGroupName)] = $ldapGroupMembers;
//        }
        $ldapGroupsSafe = $ldapGroups;
        // Connect
        $this->logger->notice("Establishing Gitlab connection.", [
            "instance" => $gitlabInstance,
            "url" => $gitlabConfig["url"],
        ]);

        $this->logger->debug("Gitlab: Connecting");

        $gitlab = new Client();
        $gitlab->setUrl($gitlabConfig["url"]);
        $gitlab->authenticate($gitlabConfig["token"], Client::AUTH_HTTP_TOKEN);


        // << Handle users
        $usersSync = [
            "found" => [],  // All existing Gitlab users
            "foundNum" => 0,
            "new" => [],  // Users in LDAP but not Gitlab
            "newNum" => 0,
            "extra" => [],  // Users in Gitlab but not LDAP
            "extraNum" => 0,
            "update" => [],  // Users in both LDAP and Gitlab
            "updateNum" => 0,
        ];

        // Find all existing Gitlab users
        $this->logger->notice("Finding all existing Gitlab users...");
        $p = 0;

        while (is_array(
                $gitlabUsers = $gitlab->users()->all(["page" => ++$p, "per_page" => 100])
            ) && !empty($gitlabUsers)) {
            foreach ($gitlabUsers as $i => $gitlabUser) {
//                pre($gitlabUsers,1);
                $n = $i + 1;
                if (!is_array($gitlabUser)) {
                    $this->logger->error(sprintf("User #%d: Not an array.", $n));
                    continue;
                }

                if (!isset($gitlabUser["id"])) {
                    $this->logger->error(sprintf("User #%d: Missing ID.", $n));
                    continue;
                }

                if (!($gitlabUserId = intval($gitlabUser["id"]))) {
                    $this->logger->error(sprintf("User #%d: Empty ID.", $n));
                    continue;
                }

                if (!isset($gitlabUser["username"])) {
                    $this->logger->error(sprintf("User #%d: Missing user name.", $n));
                    continue;
                }

                if (!($gitlabUserName = trim($gitlabUser["username"]))) {
                    $this->logger->error(sprintf("User #%d: Empty user name.", $n));
                    continue;
                }

                if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                    $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                    continue;
                }

                $this->logger->info(sprintf("Found Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));
                if (isset($usersSync["found"][$gitlabUserId]) || $this->recursive_find_pair(
                        ["name" => $gitlabUserName],
                        $usersSync["found"]
                    )) {
                    $this->logger->warning(
                        sprintf("Duplicate Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName)
                    );
                    continue;
                }
                $gitlabUserKeys = $gitlab->users()->userKeys($gitlabUserId);
                $userKeys = [];
                if (!empty($gitlabUserKeys)) {
                    foreach ($gitlabUserKeys as $key) {
                        if (substr($key["key"], 0, 8) != 'ssh-rsa ') {
                            continue;
                        }
                        $fingerprint = $this->getFingerprint($key["key"]);
                        $userKeys[] = ["key" => $key["key"], "id" => $key["id"], "fingerprint" => $fingerprint];
                    }
                }
                $gitlabUser["keys"] = $userKeys;
                $usersSync["found"][$gitlabUserId] = $gitlabUser;
            }
        }

        asort($usersSync["found"]);
        $this->logger->notice(sprintf("%d Gitlab user(s) found.", $usersSync["foundNum"] = count($usersSync["found"])));

        // Create directory users of which don't exist in Gitlab
        $this->logger->notice("Creating directory users of which don't exist in Gitlab...");
        foreach ($ldapUsers as $ldapUserName => $ldapUserDetails) {
            if ($this->in_array_i($ldapUserName, $this->getBuiltInUserNames())) {
                $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $ldapUserName));
                continue;
            }

            if ($this->in_array_i($ldapUserName, $this->config["gitlab"]["options"]["userNamesToIgnore"])) {
                $this->logger->info(sprintf("User \"%s\" in ignore list.", $ldapUserName));
                continue;
            }

            $gitlabUserName = trim($ldapUserName);
            if ($this->recursive_find_pair(["username" => $gitlabUserName], $usersSync["found"])) {
                continue;
            }

            $this->logger->info(sprintf("Creating Gitlab user \"%s\" [%s].", $gitlabUserName, $ldapUserDetails["dn"]));
            $gitlabUser = null;

            $gitlabUser = $this->create_user(
                $gitlabUserName,
                $ldapUserDetails,
                $gitlab,
                $gitlabConfig["ldapServerName"]
            );


            $gitlabUserId = (is_array($gitlabUser) && isset($gitlabUser["id"]) && is_int(
                    $gitlabUser["id"]
                )) ? $gitlabUser["id"] : sprintf("dry:%s", $ldapUserDetails["dn"]);
            $usersSync["new"][$gitlabUserId] = $gitlabUser;

            $this->gitlabApiCoolDown();
        }

        asort($usersSync["new"]);
        $this->logger->notice(sprintf("%d Gitlab user(s) created.", $usersSync["newNum"] = count($usersSync["new"])));

        // Disable Gitlab users of which don't exist in directory
        $this->logger->notice("Disabling Gitlab users of which don't exist in directory...");
        foreach ($usersSync["found"] as $gitlabUserId => $gitlabUser) {
            $gitlabUserName = $gitlabUser["username"];
            if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                continue;
            }

            if ($this->in_array_i($gitlabUserName, $this->config["gitlab"]["options"]["userNamesToIgnore"])) {
                $this->logger->info(sprintf("User \"%s\" in ignore list.", $gitlabUserName));
                continue;
            }

            if (isset($ldapUsers[$gitlabUserName]) && is_array($ldapUsers[$gitlabUserName])) {
                continue;
            }

            $this->logger->warning(sprintf("Disabling Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));

            !$this->dryRun ? ($gitlab->users()->block($gitlabUserId)) : $this->logger->warning(
                "Operation skipped due to dry run."
            );
            !$this->dryRun ? ($gitlab->users()->update($gitlabUserId, [
                "admin" => false,
                "can_create_group" => false,
                "external" => true,
            ])) : $this->logger->warning("Operation skipped due to dry run.");

            $usersSync["extra"][$gitlabUserId] = ["name" => $gitlabUserName];

            $this->gitlabApiCoolDown();
        }

        asort($usersSync["extra"]);
        $this->logger->notice(
            sprintf("%d Gitlab user(s) disabled.", $usersSync["extraNum"] = count($usersSync["extra"]))
        );

        // Update users of which were already in both Gitlab and the directory
        $this->logger->notice("Updating users of which were already in both Gitlab and the directory...");
        foreach ($usersSync["found"] as $gitlabUserId => $gitlabUser) {
            $gitlabUserName = $gitlabUser["username"];
            if (!empty($usersSync["new"][$gitlabUserId]) || !empty($usersSync["extra"][$gitlabUserId])) {
                continue;
            }

            if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                continue;
            }

            if ($this->in_array_i($gitlabUserName, $this->config["gitlab"]["options"]["userNamesToIgnore"])) {
                $this->logger->info(sprintf("User \"%s\" in ignore list.", $gitlabUserName));
                continue;
            }

            if ($gitlab->users()->all(["username" => $gitlabUserName, "blocked" => true])) {
                $this->logger->info(sprintf("Enabling Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));
                !$this->dryRun ? ($gitlab->users()->unblock($gitlabUserId)) : $this->logger->warning(
                    "Operation skipped due to dry run."
                );
            }

            $this->update_user($gitlabUser, $ldapUsers, $gitlab, $gitlabConfig["ldapServerName"]);

            $usersSync["update"][$gitlabUserId] = ["name" => $gitlabUserName];

            $this->gitlabApiCoolDown();
        }

        asort($usersSync["update"]);
        $this->logger->notice(
            sprintf("%d Gitlab user(s) updated.", $usersSync["updateNum"] = count($usersSync["update"]))
        );
        // >> Handle users

        // << Handle groups
        $groupsSync = [
            "found" => [],  // All existing Gitlab groups
            "foundNum" => 0,
            "new" => [],  // Groups in LDAP but not Gitlab
            "newNum" => 0,
            "extra" => [],  // Groups in Gitlab but not LDAP
            "extraNum" => 0,
            "update" => [],  // Groups in both LDAP and Gitlab
            "updateNum" => 0,
        ];

        // Find all existing Gitlab groups
        $this->logger->notice("Finding all existing Gitlab groups...");
        $p = 0;

        while (is_array(
                $gitlabGroups = $gitlab->groups()->all(["page" => ++$p, "per_page" => 100, "all_available" => true])
            ) && !empty($gitlabGroups)) {
            foreach ($gitlabGroups as $i => $gitlabGroup) {

                $validGroup = $this->validateGitlabGroup($gitlabGroup, $i, $groupsSync);
                if (!$validGroup) {
                    continue;
                }
                $gitlabParentGroupId = intval($gitlabGroup["parent_id"]);
                $gitlabGroupId = intval($gitlabGroup["id"]);
                $gitlabGroupName = trim($gitlabGroup["name"]);
                $gitlabGroupPath = strtolower(trim($gitlabGroup["path"]));
                $gitlabGroupFullPath = strtolower(trim($gitlabGroup["full_path"]));


                $this->logger->info(
                    sprintf(
                        "Found Gitlab group #%d \"%s\" [%s], parent: [%s].",
                        $gitlabGroupId,
                        $gitlabGroupName,
                        $gitlabGroupPath,
                        $gitlabParentGroupId
                    )
                );

                $groupsSync["found"][$gitlabGroupFullPath] = [
                    "id" => $gitlabGroupId,
                    "name" => $gitlabGroupName,
                    "path" => $gitlabGroupPath,
                    "full_path" => $gitlabGroupFullPath,
                ];
            }
        }

        asort($groupsSync["found"]);
        $this->logger->notice(
            sprintf("%d Gitlab group(s) found.", $groupsSync["foundNum"] = count($groupsSync["found"]))
        );
        // Create directory groups of which don't exist in Gitlab
        $this->logger->notice("Creating directory groups of which don't exist in Gitlab...");
        foreach ($ldapGroupsSafe as $ldapGroupName => $ldapGroupMembers) {
            $validGroup = $this->validateLdapGroup($ldapGroupName, $groupsSync);
            if (!$validGroup) {
                continue;
            }

            $parentId = null;
            // Check if we might have a subgroup:
            if (strpos($ldapGroupName, "/")) {
                // todo: add sub subgroup support (parent/child/.../...)
                // get parent id
                list($parent, $child) = explode("/", $ldapGroupName);
                if ($parent && isset($groupsSync["found"][strtolower($parent)])) {
                    $parentId = intval($groupsSync["found"][strtolower($parent)]["id"]);
                } elseif ($parent && isset($groupsSync["new"][strtolower($parent)])) {
                    $parentId = intval($groupsSync["new"][strtolower($parent)]["id"]);
                } else {
                    //create parent first
                    $gitlabGroupName = $slugifyGitlabName->slugify($parent);
                    $gitlabGroupPath = $slugifyGitlabPath->slugify($parent);

                    $this->logger->info(
                        sprintf("Creating Parent Gitlab group \"%s\" [%s].", $gitlabGroupName, $gitlabGroupPath)
                    );
                    $gitlabGroup = null;

                    !$this->dryRun ? ($gitlabGroup = $gitlab->groups()->create(
                        $gitlabGroupName,
                        $gitlabGroupPath,
                        null,
                        'private',
                        null,
                        null,
                        $parentId
                    )) : $this->logger->warning("Operation skipped due to dry run.");

                    if (is_array($gitlabGroup) && isset($gitlabGroup["id"]) && is_int(
                            $gitlabGroup["id"]
                        )) {
                        $parentId = $gitlabGroup["id"];
                        $gitlabGroupFullPath = $gitlabGroup["full_path"];
                    } else {
                        $parentId = sprintf("dry:%s", $gitlabGroupPath);
                        $gitlabGroupFullPath = sprintf("dry:%s", $gitlabGroupPath);
                    }
                    $groupsSync["new"][$gitlabGroupFullPath] = [
                        "id" => $parentId,
                        "name" => $gitlabGroupName,
                        "path" => $gitlabGroupPath,
                        "full_path" => $gitlabGroupFullPath,
                    ];
                }
                $ldapGroupName = $child;
            }
            $gitlabGroupName = $slugifyGitlabName->slugify($ldapGroupName);
            $gitlabGroupPath = $slugifyGitlabPath->slugify($ldapGroupName);


            if ((!is_array(
                        $ldapGroupMembers
                    ) || empty($ldapGroupMembers)) && !$this->config["gitlab"]["options"]["createEmptyGroups"]) {
                $this->logger->warning(
                    sprintf(
                        "Not creating Gitlab group \"%s\" [%s]: No members in directory group, or config gitlab->options->createEmptyGroups is disabled.",
                        $gitlabGroupName,
                        $gitlabGroupPath
                    )
                );
                continue;
            }

            $this->logger->info(sprintf("Creating Gitlab group \"%s\" [%s].", $gitlabGroupName, $gitlabGroupPath));
            $gitlabGroup = null;

            !$this->dryRun ? ($gitlabGroup = $gitlab->groups()->create(
                $gitlabGroupName,
                $gitlabGroupPath,
                null,
                'private',
                null,
                null,
                $parentId
            )) : $this->logger->warning("Operation skipped due to dry run.");

            if (is_array($gitlabGroup) && isset($gitlabGroup["id"]) && is_int(
                    $gitlabGroup["id"]
                )) {
                $gitlabGroupId = $gitlabGroup["id"];
                $gitlabGroupFullPath = $gitlabGroup["full_path"];
            } else {
                $gitlabGroupId = sprintf("dry:%s", $gitlabGroupPath);
                $gitlabGroupFullPath = sprintf("dry:%s", $gitlabGroupPath);
            }
            $groupsSync["new"][$gitlabGroupFullPath] = [
                "id" => $gitlabGroupId,
                "name" => $gitlabGroupName,
                "path" => $gitlabGroupPath,
                "full_path" => $gitlabGroupFullPath,
            ];

            $this->gitlabApiCoolDown();
        }

        asort($groupsSync["new"]);
        $this->logger->notice(
            sprintf("%d Gitlab group(s) created.", $groupsSync["newNum"] = count($groupsSync["new"]))
        );

        // potential gitlab groups created, now lowercase ldap groups for easier reference
        $ldapGroupsSafe = array_change_key_case($ldapGroupsSafe, CASE_LOWER);

        // Delete Gitlab groups of which don't exist in directory
        $this->logger->notice("Deleting Gitlab groups of which don't exist in directory...");
        foreach ($groupsSync["found"] as $gitlabGroupFullPath => $gitlabGroup) {
            $gitlabGroupName = $gitlabGroup["name"];
            $gitlabGroupId = $gitlabGroup["id"];
            $gitlabGroupPath = $gitlabGroup["id"];

            if ($this->array_key_exists_i($gitlabGroupFullPath, $ldapGroupsSafe)) {
                continue;
            }

            if (!$this->config["gitlab"]["options"]["deleteExtraGroups"]) {
                $this->logger->info(
                    sprintf(
                        "Not deleting Gitlab group #%d \"%s\" [%s]: Config gitlab->options->deleteExtraGroups is disabled.",
                        $gitlabGroupId,
                        $gitlabGroupName,
                        $gitlabGroupPath
                    )
                );
                continue;
            }

            if (is_array(
                    $gitlabGroupProjects = $gitlab->groups()->projects($gitlabGroupId)
                ) && ($gitlabGroupProjectsNum = count($gitlabGroupProjects)) >= 1) {
                $this->logger->info(
                    sprintf(
                        "Not deleting Gitlab group #%d \"%s\" [%s]: It contains %d project(s).",
                        $gitlabGroupId,
                        $gitlabGroupName,
                        $gitlabGroupPath,
                        $gitlabGroupProjectsNum
                    )
                );
                continue;
            }

            if (is_array(
                    $gitlabGroupSubGroups = $gitlab->groups()->subgroups($gitlabGroupId)
                ) && ($gitlabGroupSubGroupsNum = count($gitlabGroupSubGroups)) >= 1) {
                $this->logger->info(
                    sprintf(
                        "Not deleting Gitlab group #%d \"%s\" [%s]: It contains %d subgroup(s).",
                        $gitlabGroupId,
                        $gitlabGroupName,
                        $gitlabGroupPath,
                        $gitlabGroupSubGroupsNum
                    )
                );
                continue;
            }

            $this->logger->warning(
                sprintf("Deleting Gitlab group #%d \"%s\" [%s].", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath)
            );
            $gitlabGroup = null;

            !$this->dryRun ? ($gitlab->groups()->remove($gitlabGroupId)) : $this->logger->warning(
                "Operation skipped due to dry run."
            );

            $groupsSync["extra"][$gitlabGroupFullPath] = [
                "id" => $gitlabGroupId,
                "name" => $gitlabGroupName,
                "path" => $gitlabGroupPath,
                "full_path" => $gitlabGroupFullPath,
            ];

            $this->gitlabApiCoolDown();
        }

        asort($groupsSync["extra"]);
        $this->logger->notice(
            sprintf("%d Gitlab group(s) deleted.", $groupsSync["extraNum"] = count($groupsSync["extra"]))
        );

        // Update groups of which were already in both Gitlab and the directory
//        $this->logger->notice("Updating groups of which were already in both Gitlab and the directory...");
//        foreach ($groupsSync["found"] as $gitlabGroupFullPath => $gitlabGroup) {
//            $gitlabGroupId = $gitlabGroup["id"];
//            $gitlabGroupName = $gitlabGroup["name"];
//            $gitlabGroupPath = $gitlabGroup["path"];
//
//            if (!empty($groupsSync["new"][$gitlabGroupId]) || !empty($groupsSync["extra"][$gitlabGroupId])) {
//                continue;
//            }
//
//            $this->logger->info(
//                sprintf("Updating Gitlab group #%d \"%s\" [%s].", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath)
//            );
//            $gitlabGroup = null;
//
//            if (!isset($ldapGroupsSafe[$gitlabGroupName]) || !is_array($ldapGroupsSafe[$gitlabGroupName])) {
//                $this->logger->info(sprintf("Gitlab group \"%s\" has no LDAP details available.", $gitlabGroupName));
//                continue;
//            }
//            $ldapGroupMembers = $ldapGroupsSafe[$gitlabGroupName];
//
//            /*
//            !$this->dryRun ? ($gitlabGroup = $gitlab->groups()->update($gitlabGroupId, [
//                // "name"              => $gitlabGroupName,
//                // No point updating that. ^
//                // If the CN changes so will that bit of the DN anyway, so this can't be detected with a custom attribute containing the Gitlab group ID written back to group's LDAP object.
//                "path"              => $gitlabGroupPath,
//            ])) : $this->logger->warning("Operation skipped due to dry run.");
//             */
//
//            $groupsSync["update"][$gitlabGroupFullPath] =["id"=>$gitlabGroupId, "name"=>$gitlabGroupName,"path"=>$gitlabGroupPath,"full_path"=>$gitlabGroupFullPath];
//
//            /* Not required until group updates can be detected as per above.
//            $this->gitlabApiCoolDown();
//             */
//        }
//
//        asort($groupsSync["update"]);
//        $this->logger->notice(
//            sprintf("%d Gitlab group(s) updated.", $groupsSync["updateNum"] = count($groupsSync["update"]))
//        );
        // >> Handle groups

        // << Handle group memberships
        $usersToSyncMembership = ($usersSync["found"] + $usersSync["new"] + $usersSync["update"]);
        asort($usersToSyncMembership);
        $groupsToSyncMembership = ($groupsSync["found"] + $groupsSync["new"] + $groupsSync["update"]);
        asort($groupsToSyncMembership);
//pre($usersSync,1);
//pre($usersToSyncMembership);
//pre($groupsToSyncMembership);
//pre($ldapGroupsSafe);
        $this->logger->notice("Synchronising Gitlab group members with directory group members...");
        foreach ($groupsToSyncMembership as $gitlabGroupFullPath => $gitlabGroup) {

            $gitlabGroupId = $gitlabGroup["id"];
            $gitlabGroupPath = $gitlabGroup["path"];
            $gitlabGroupName = $gitlabGroup["name"];


            $membersOfThisGroup = [];
            foreach ($usersToSyncMembership as $gitlabUserId => $gitlabUser) {
                $gitlabUserName = $gitlabUser["username"];
                if (!isset($ldapGroupsSafe[$gitlabGroupFullPath]) || !is_array($ldapGroupsSafe[$gitlabGroupFullPath])) {
                    $this->logger->warning(
                        sprintf(
                            "Group \"%s\" doesn't appear to exist at path \"%s\". (Is this a sub-group? Sub-groups are not supported yet.)",
                            $gitlabGroupName,
                            $gitlabGroupFullPath
                        )
                    );
                    continue;
                }

                if (!$this->in_array_i($gitlabUserName, $ldapGroupsSafe[$gitlabGroupFullPath])) {
                    continue;
                }

                $membersOfThisGroup[$gitlabUserId] = $gitlabUserName;
            }
            asort($membersOfThisGroup);
            $this->logger->notice(
                sprintf(
                    "Synchronising %d member(s) for group #%d \"%s\" [%s]...",
                    count($membersOfThisGroup),
                    $gitlabGroupId,
                    $gitlabGroupName,
                    $gitlabGroupPath
                )
            );

            $userGroupMembersSync = [
                "found" => [],
                "foundNum" => 0,
                "new" => [],
                "newNum" => 0,
                "extra" => [],
                "extraNum" => 0,
                "update" => [],
                "updateNum" => 0,
            ];

            // Find existing group members
            $this->logger->notice("Finding existing group members...");
            $p = 0;

            while (is_array(
                    $gitlabUsers = $gitlab->groups()->members($gitlabGroupId, ["page" => ++$p, "per_page" => 100])
                ) && !empty($gitlabUsers)) {
                foreach ($gitlabUsers as $i => $gitlabUser) {
                    $n = $i + 1;

                    if (!is_array($gitlabUser)) {
                        $this->logger->error(sprintf("Group member #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($gitlabUser["id"])) {
                        $this->logger->error(sprintf("Group member #%d: Missing ID.", $n));
                        continue;
                    }

                    if (!($gitlabUserId = intval($gitlabUser["id"]))) {
                        $this->logger->error(sprintf("Group member #%d: Empty ID.", $n));
                        continue;
                    }

                    if (!isset($gitlabUser["username"])) {
                        $this->logger->error(sprintf("Group member #%d: Missing user name.", $n));
                        continue;
                    }

                    if (!($gitlabUserName = trim($gitlabUser["username"]))) {
                        $this->logger->error(sprintf("Group member #%d: Empty user name.", $n));
                        continue;
                    }

                    if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                        $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                        continue;
                    }

                    $this->logger->info(
                        sprintf("Found Gitlab group member #%d \"%s\".", $gitlabUserId, $gitlabUserName)
                    );
                    if (isset($userGroupMembersSync["found"][$gitlabUserId]) || $this->in_array_i(
                            $gitlabUserName,
                            $userGroupMembersSync["found"]
                        )) {
                        $this->logger->warning(
                            sprintf("Duplicate Gitlab group member #%d \"%s\".", $gitlabUserId, $gitlabUserName)
                        );
                        continue;
                    }

                    $userGroupMembersSync["found"][$gitlabUserId] = $gitlabUserName;
                }
            }

            asort($userGroupMembersSync["found"]);
            $this->logger->notice(
                sprintf(
                    "%d Gitlab group \"%s\" [%s] member(s) found.",
                    $userGroupMembersSync["foundNum"] = count($userGroupMembersSync["found"]),
                    $gitlabGroupName,
                    $gitlabGroupPath
                )
            );

            // Add missing group members
            $this->logger->notice("Adding missing group members...");
            foreach ($membersOfThisGroup as $gitlabUserId => $gitlabUserName) {
                if (isset($userGroupMembersSync["found"][$gitlabUserId]) && $userGroupMembersSync["found"][$gitlabUserId] == $gitlabUserName) {
                    continue;
                }

                if (!isset($gitlabUserName)) {
                    continue;
                }

                $this->logger->info(
                    sprintf(
                        "Adding user #%d \"%s\" to group #%d \"%s\" [%s].",
                        $gitlabUserId,
                        $gitlabUserName,
                        $gitlabGroupId,
                        $gitlabGroupName,
                        $gitlabGroupPath
                    )
                );

                !$this->dryRun ? ($gitlab->groups()->addMember(
                    $gitlabGroupId,
                    $gitlabUserId,
                    $this->config["gitlab"]["options"]["newMemberAccessLevel"]
                )) : $this->logger->warning("Operation skipped due to dry run.");

                $userGroupMembersSync["new"][$gitlabUserId] = $gitlabUserName;

                $this->gitlabApiCoolDown();
            }

            asort($userGroupMembersSync["new"]);
            $this->logger->notice(
                sprintf(
                    "%d Gitlab group \"%s\" [%s] member(s) added.",
                    $userGroupMembersSync["newNum"] = count($userGroupMembersSync["new"]),
                    $gitlabGroupName,
                    $gitlabGroupPath
                )
            );

            // Delete extra group members
            $this->logger->notice("Deleting extra group members...");
            foreach ($userGroupMembersSync["found"] as $gitlabUserId => $gitlabUserName) {
                if (isset($membersOfThisGroup[$gitlabUserId]) && $membersOfThisGroup[$gitlabUserId] == $gitlabUserName) {
                    continue;
                }

                $this->logger->info(
                    sprintf(
                        "Deleting user #%d \"%s\" from group #%d \"%s\" [%s].",
                        $gitlabUserId,
                        $gitlabUserName,
                        $gitlabGroupId,
                        $gitlabGroupName,
                        $gitlabGroupPath
                    )
                );

                !$this->dryRun ? ($gitlab->groups()->removeMember(
                    $gitlabGroupId,
                    $gitlabUserId
                )) : $this->logger->warning("Operation skipped due to dry run.");

                $userGroupMembersSync["extra"][$gitlabUserId] = $gitlabUserName;

                $this->gitlabApiCoolDown();
            }

            asort($userGroupMembersSync["extra"]);
            $this->logger->notice(
                sprintf(
                    "%d Gitlab group \"%s\" [%s] member(s) deleted.",
                    $userGroupMembersSync["extraNum"] = count($userGroupMembersSync["extra"]),
                    $gitlabGroupName,
                    $gitlabGroupPath
                )
            );

            // Update existing group members
            /* This isn't needed...
            $this->logger->notice("Updating existing group members...");
            foreach ($userGroupMembersSync["found"] as $gitlabUserId => $gitlabUserName) {
                if ((isset($userUserMembersSync["new"][$gitlabUserId]) && $userUserMembersSync["new"][$gitlabUserId]) == $gitlabUserName || (isset($userUserMembersSync["extra"][$gitlabUserId]) && $userUserMembersSync["extra"][$gitlabUserId] == $gitlabUserName)) {
                    continue;
                }

                if (!isset($membersOfThisGroup[$gitlabUserId]) || $membersOfThisGroup[$gitlabUserId] != $gitlabUserName) {
                    continue;
                }

                $this->logger->info(sprintf("Updating user #%d \"%s\" in group #%d \"%s\" [%s].", $gitlabUserId, $gitlabUserName, $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
                $gitlabGroupMember = null;

                !$this->dryRun ? ($gitlabGroupMember = $gitlab->groups()->saveMember($gitlabGroupId, $gitlabUserId, $config["gitlab"]["options"]["newMemberAccessLevel"])) : $this->logger->warning("Operation skipped due to dry run.");

                $userGroupMembersSync["update"][$gitlabUserId] = $gitlabUserName;
            }

            asort($userGroupMembersSync["update"]);
            $this->logger->notice(sprintf("%d Gitlab group \"%s\" [%s] member(s) updated.", $userGroupMembersSync["updateNum"] = count($userGroupMembersSync["update"]), $gitlabGroupName, $gitlabGroupPath));

            $this->gitlabApiCoolDown();
             */
        }
        // >> Handle group memberships

        // Disconnect
        $this->logger->debug("Gitlab: Unbinding");
        $gitlab = null;

        $this->logger->notice("Gitlab connection closed.");
    }


    /**
     * Case-insensitive recursive search for a key=>value pair
     *
     * @param array $needle
     * @param array $haystack
     *
     * @return bool
     */
    function recursive_find_pair(array $needle, array $haystack): bool
    {
        $iterator = new RecursiveArrayIterator($haystack);
        $recursive = new RecursiveIteratorIterator(
            $iterator,
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($recursive as $key => $value) {
            foreach ($needle as $searchKey => $searchValue) {
                if (strtolower($key) === strtolower($searchKey) && strtolower($value) === strtolower($searchValue)) {
                    return true;
                }

            }
        }

        return false;
    }

    /**
     * Case-insensitive `in_array()`.
     *
     * @param bool|int|float|string $needle
     * @param array                 $haystack
     *
     * @return bool
     */
    private function in_array_i($needle, array $haystack): bool
    {
        if ("" === ($needle = strtolower(strval($needle)))) {
            throw new UnexpectedValueException("Needle not specified.");
        }

        return in_array($needle, array_map("strtolower", $haystack));
    }

    /**
     * Case insensitive `array_key_exists()`.
     *
     * @param bool|int|float|string $key
     * @param array                 $haystack
     *
     * @return bool
     */
    private function array_key_exists_i($key, array $haystack): bool
    {
        if ("" === ($key = strtolower(strval($key)))) {
            throw new UnexpectedValueException("Key not specified.");
        }

        foreach (array_change_key_case($haystack, CASE_LOWER) as $k => $v) {
            if ($k === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a random password.
     *
     * @param int $length Length
     *
     * @return string         Password
     * @throws Exception
     * @noinspection PhpSameParameterValueInspection
     */
    private function generateRandomPassword(int $length): string
    {
        if ($length < 1) {
            throw new UnexpectedValueException("Length must be at least 1.");
        }

        $password = "";
        $chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $charsNum = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charsNum - 1)];
        }

        return $password;
    }

    /**
     * Get a list of built-in usernames, of which should be ignored by this application.
     * @return array<string>
     */
    private function getBuiltInUserNames(): array
    {
        return ["root", "ghost", "support-bot", "alert-bot"];
    }

    /**
     * Wait a bit of time between each Gitlab API request to avoid HTTP 500 errors when doing too many requests in a
     * short time.
     * @return void
     */
    private function gitlabApiCoolDown(): void
    {
        if ($this->dryRun) {
            return; // Not required for dry runs
        }

        usleep(self::API_COOL_DOWN_USECONDS);
    }

    private function validateGitlabGroup($gitlabGroup, $i, $groupsSync): bool
    {
        if (!is_array($gitlabGroup)) {
            $this->logger->error(sprintf("Group #%d: Not an array.", $i));

            return false;
        }

        if (!isset($gitlabGroup["id"])) {
            $this->logger->error(sprintf("Group #%d: Missing ID.", $i));

            return false;
        }

        if (!isset($gitlabGroup["name"])) {
            $this->logger->error(sprintf("Group #%d: Missing name.", $i));

            return false;
        }

        if (!($gitlabGroupId = intval($gitlabGroup["id"]))) {
            $this->logger->error(sprintf("Group #%d: Empty ID.", $i));

            return false;
        }
        if (!($gitlabGroupName = trim($gitlabGroup["name"]))) {
            $this->logger->error(sprintf("Group #%d: Empty name.", $i));

            return false;
        }

        if (!($gitlabGroupPath = trim($gitlabGroup["path"]))) {
            $this->logger->error(sprintf("Group #%d: Empty path.", $i));

            return false;
        }

        if (!($gitlabGroupFullPath = trim($gitlabGroup["full_path"]))) {
            $this->logger->error(sprintf("Group #%d: Empty full path.", $i));

            return false;
        }

        if ("Root" == $gitlabGroupName) {
            $this->logger->info("Gitlab built-in root group will be ignored.");

            return false; // The Gitlab root group should never be updated from LDAP.
        }

        if ("Users" == $gitlabGroupName) {
            $this->logger->info("Gitlab built-in users group will be ignored.");

            return false; // The Gitlab users group should never be updated from LDAP.
        }
        if ("GitLab Instance" == $gitlabGroupName) {
            $this->logger->info("Gitlab built-in users group will be ignored.");

            return false; // The Gitlab users group should never be updated from LDAP.
        }
        if (isset($groupsSync["found"][$gitlabGroupFullPath]) || $this->recursive_find_pair(
                ["id" => $gitlabGroupId],
                $groupsSync["found"]
            )) {
            $this->logger->warning(
                sprintf(
                    "Duplicate Gitlab group %d \"%s\" [%s].",
                    $gitlabGroupId,
                    $gitlabGroupName,
                    $gitlabGroupPath
                )
            );

        }
        if ($this->in_array_i($gitlabGroupName, $this->config["gitlab"]["options"]["groupNamesToIgnore"])) {
            $this->logger->info(sprintf("Group \"%s\" in ignore list.", $gitlabGroupName));

            return false;
        }

        return true;
    }

    private function validateLdapGroup(string $ldapGroupName, array $groupsSync): bool
    {
        if ("Root" == $ldapGroupName) {
            $this->logger->info("Gitlab built-in root group will be ignored.");

            return false; // The Gitlab root group should never be updated from LDAP.
        }

        if ("Users" == $ldapGroupName) {
            $this->logger->info("Gitlab built-in users group will be ignored.");

            return false; // The Gitlab users group should never be updated from LDAP.
        }

        if ($this->in_array_i($ldapGroupName, $this->config["gitlab"]["options"]["groupNamesToIgnore"])) {
            $this->logger->info(sprintf("Group \"%s\" in ignore list.", $ldapGroupName));

            return false;
        }

        if ($this->recursive_find_pair(["full_path" => $ldapGroupName], $groupsSync["found"])) {
            return false;
        }

        return true;
    }

    /**
     * @param $key
     *
     * @return string
     */
    private function getFingerprint($key): string
    {
        $content = explode(' ', $key, 3);

        return join(':', str_split(md5(base64_decode($content[1])), 2));
    }

    /**
     * @param string $gitlabUserName
     * @param        $ldapUserDetails
     * @param Client $gitlab
     * @param        $ldapServerName
     *
     * @return mixed
     * @throws Exception
     */
    private function create_user(string $gitlabUserName, $ldapUserDetails, Client $gitlab, $ldapServerName)
    {
        $gitlabUser = null;
        $gitlabUserPassword = $this->generateRandomPassword(12);
        $this->logger->debug(
            sprintf(
                "Password for Gitlab user \"%s\" [%s] will be: %s",
                $gitlabUserName,
                $ldapUserDetails["dn"],
                $gitlabUserPassword
            )
        );

        try {
            !$this->dryRun ? ($gitlabUser = $gitlab->users()->create(
                $ldapUserDetails["email"],
                $gitlabUserPassword,
                [
                    "username" => $gitlabUserName,
                    "reset_password" => false,
                    "name" => $ldapUserDetails["fullName"],
                    "extern_uid" => $ldapUserDetails["dn"],
                    "provider" => $ldapServerName,
                    "email" => $ldapUserDetails["email"],
                    "admin" => $ldapUserDetails["isAdmin"],
                    "can_create_group" => $ldapUserDetails["isAdmin"],
                    "skip_confirmation" => true,
                    "external" => $ldapUserDetails["isExternal"],
                ]
            )) : $this->logger->warning("Operation skipped due to dry run.");
        } catch (Exception $e) {
            // Permit continue when user email address already used by another account
            if ("Email has already been taken" === $e->getMessage()) {
                $this->logger->error(
                    sprintf(
                        "Gitlab user \"%s\" [%s] was not created, email address already used by another account.",
                        $gitlabUserName,
                        $ldapUserDetails["dn"]
                    )
                );
            }

            if ($this->continueOnFail) {
                $this->gitlabApiCoolDown();

                return null;
            }

            throw $e;
        }

        $this->update_ssh_key($ldapUserDetails, $gitlabUser, $gitlab);

        return $gitlabUser;
    }


    /**
     * @param array  $gitlabUser
     * @param array  $ldapUsers
     * @param Client $gitlab
     * @param        $ldapServerName
     */
    private function update_user(
        array $gitlabUser,
        array $ldapUsers,
        Client $gitlab,
        $ldapServerName
    ): void {
        $this->logger->info(sprintf("Updating Gitlab user #%d \"%s\".", $gitlabUser["id"], $gitlabUser["username"]));

        if (!isset($ldapUsers[$gitlabUser["username"]]) || !is_array($ldapUsers[$gitlabUser["username"]]) || count(
                $ldapUsers[$gitlabUser["username"]]
            ) < 5) {
            $this->logger->info(sprintf("Gitlab user \"%s\" has no LDAP details available.", $gitlabUser["username"]));

            return;
        }
        $ldapUserDetails = $ldapUsers[$gitlabUser["username"]];

        try {
            !$this->dryRun ? ($gitlab->users()->update($gitlabUser["id"], [
                // "username"          => $gitlabUserName,
                // No point updating that. ^
                // If the UID changes so will that bit of the DN anyway, so this can't be detected with a custom attribute containing the Gitlab user ID written back to user's LDAP object.
                "reset_password" => false,
                "name" => $ldapUserDetails["fullName"],
                "extern_uid" => $ldapUserDetails["dn"],
                "provider" => $ldapServerName,
                "email" => $ldapUserDetails["email"],
                "admin" => $ldapUserDetails["isAdmin"],
                "can_create_group" => $ldapUserDetails["isAdmin"],
                "skip_confirmation" => true,
                "external" => $ldapUserDetails["isExternal"],
            ])) : $this->logger->warning("Operation skipped due to dry run.");
        } catch (Exception $e) {
            // do nothing
        }

        $this->update_ssh_key($ldapUserDetails, $gitlabUser, $gitlab);

    }

    /**
     * @param array  $ldapUserDetails
     * @param array  $gitlabUser
     * @param Client $gitlab
     *
     */
    private function update_ssh_key(array $ldapUserDetails, array $gitlabUser, Client $gitlab)
    {
        // check if we have ldap keys and need to add
        if ($ldapUserDetails["sshKeys"] && count($ldapUserDetails["sshKeys"]) > 0) {
            foreach ($ldapUserDetails["sshKeys"] as $sshKey) {
                //check if key already exists
                if (!is_array($gitlabUser["keys"]) || count($gitlabUser["keys"]) < 1 || !$this->recursive_find_pair(
                        ["fingerprint" => $sshKey["fingerprint"]],
                        $gitlabUser["keys"]
                    )) {

                    $this->add_ssh_key($gitlabUser, $sshKey, $gitlab, $ldapUserDetails["email"]);
                }
            }
        }

        //remove gitlab keys not in ldap
        if ($gitlabUser["keys"] && count($gitlabUser["keys"]) > 0) {
            foreach ($gitlabUser["keys"] as $sshKey) {
                //check if key already exists
                if (!is_array($ldapUserDetails["sshKeys"]) || count($ldapUserDetails["sshKeys"]) < 1 || !$this->recursive_find_pair(
                        ["fingerprint" => $sshKey["fingerprint"]],
                        $ldapUserDetails["sshKeys"]
                    )) {

                    $this->remove_ssh_key($gitlabUser, $sshKey, $gitlab);
                }
            }
        }
    }

    /**
     * @param array  $gitlabUser
     * @param array  $sshKey
     * @param Client $gitlab
     * @param string $email
     *
     * @return void
     */
    private function add_ssh_key(array $gitlabUser, array $sshKey, Client $gitlab, string $email)
    {
        $this->logger->debug(
            sprintf(
                "Adding SSH key for Gitlab user \"%s\" with fingerprint: [%s] ",
                $gitlabUser["username"],
                $sshKey["fingerprint"]
            )
        );
        try {
            !$this->dryRun ? ($gitlab->users()->createKeyForUser(
                $gitlabUser["id"],
                $email,
                $sshKey["key"]
            )) : $this->logger->warning("Operation skipped due to dry run.");
        } catch (Exception $e) {
            // do nothing
        }

    }

    /**
     * @param array  $gitlabUser
     * @param array  $sshKey
     * @param Client $gitlab
     */
    private function remove_ssh_key(array $gitlabUser, array $sshKey, Client $gitlab): void
    {
        $this->logger->debug(
            sprintf(
                "Removing SSH key for Gitlab user \"%s\" with fingerprint: [%s] ",
                $gitlabUser["username"],
                $sshKey["fingerprint"]
            )
        );
        try {
            !$this->dryRun ? ($gitlab->users()->removeUserKey(
                $gitlabUser["id"],
                $sshKey["id"]
            )) : $this->logger->warning("Operation skipped due to dry run.");
        } catch (Exception $e) {
            // do nothing
        }
    }
}

/**
 * Print a formatted string
 *
 * @param string|array $strPre
 * @param bool         $blnExit
 */
function pre($strPre, bool $blnExit = false)
{
    echo "\n";
    print_r($strPre);
    echo "\n";
    if ($blnExit) {
        exit();
    }
}
