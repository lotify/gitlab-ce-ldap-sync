<?php


namespace AdamReece\GitlabCeLdapSync;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Config
{
    private $logger;

    /**
     * @param $logger
     */
    public function __construct($logger)
    {
        $this->logger = $logger;
    }


    /**
     * Load configuration.
     *
     * @param string $file File
     *
     * @return array<mixed>|null       Configuration, or null if failed
     */
    public function loadConfig(string $file): ?array
    {
        if (!($file = trim($file))) {
            $this->logger->critical("Configuration file not specified.");

            return null;
        }

        if (!file_exists($file)) {
            $this->logger->critical("Configuration file not found.");

            return null;
        }

        if (!is_file($file)) {
            $this->logger->critical("Configuration file not a file.");

            return null;
        }

        if (!is_readable($file)) {
            $this->logger->critical("Configuration file not readable.");

            return null;
        }

        $yaml = null;

        try {
            $yaml = Yaml::parseFile($file);
        } catch (ParseException $e) {
            $this->logger->critical(sprintf("Configuration file could not be parsed: %s", $e->getMessage()));

            return null;
        } catch (\Exception $e) {
            $this->logger->critical(sprintf("Configuration file could not be loaded: %s", $e->getMessage()));

            return null;
        }

        if (!is_array($yaml)) {
            $this->logger->critical("Configuration format invalid.");

            return null;
        }

        if (empty($yaml)) {
            $this->logger->critical("Configuration empty.");

            return null;
        }

        return $yaml;
    }
    /**
     * Validate configuration.
     *
     * @param array<mixed>             $config   Configuration (this will be modified for type strictness and trimming)
     * @param array<string,array>|null $problems Optional output of problems indexed by type
     *
     * @return bool                               True if valid, false if invalid
     */
    public function validateConfig(array &$config, array &$problems = null): bool
    {
        if (!is_array($problems)) {
            $problems = [];
        }

        $problems = [
            "warning" => [],
            "error" => [],
        ];

        /**
         * Add a problem.
         *
         * @param string $type    Problem type (error or warning)
         * @param string $message Problem description
         *
         * @return void
         */
        $addProblem = function (string $type, string $message) use (&$problems): void {

            if (!($type = trim($type))) {
                return;
            }

            if (!isset($problems[$type]) || !is_array($problems[$type])) {
                throw new \UnexpectedValueException("Type invalid.");
            }

            if (!($message = trim($message))) {
                return;
            }

            $this->logger->$type(sprintf("Configuration: %s", $message));
            $problems[$type][] = $message;

        };

        // << LDAP
        if (!isset($config["ldap"]) || !is_array($config["ldap"])) {
            $addProblem("error", "ldap missing.");
        } else {
            if (!isset($config["ldap"]["debug"])) {
                $addProblem("warning", "ldap->debug missing. (Assuming false.)");
                $config["ldap"]["debug"] = false;
            } elseif ("" === $config["ldap"]["debug"]) {
                $addProblem("warning", "ldap->debug not specified. (Assuming false.)");
                $config["ldap"]["debug"] = false;
            } elseif (!is_bool($config["ldap"]["debug"])) {
                $addProblem("error", "ldap->debug is not a boolean.");
            }

            // << LDAP server
            if (!isset($config["ldap"]["server"]) || !is_array($config["ldap"]["server"])) {
                $addProblem("error", "ldap->server missing.");
            } else {
                if (!isset($config["ldap"]["server"]["host"])) {
                    $addProblem("error", "ldap->server->host missing.");
                } elseif (!($config["ldap"]["server"]["host"] = trim($config["ldap"]["server"]["host"]))) {
                    $addProblem("error", "ldap->server->host not specified.");
                }

                if (!isset($config["ldap"]["server"]["port"])) {
                    $addProblem(
                        "warning",
                        "ldap->server->port missing. (It will be determined by the encryption setting.)"
                    );
                    $config["ldap"]["server"]["port"] = null;
                } elseif (!($config["ldap"]["server"]["port"] = intval($config["ldap"]["server"]["port"]))) {
                    $addProblem(
                        "warning",
                        "ldap->server->port not specified. (It will be determined by the encryption setting.)"
                    );
                    $config["ldap"]["server"]["port"] = null;
                } elseif ($config["ldap"]["server"]["port"] < 1 || $config["ldap"]["server"]["port"] > 65535) {
                    $addProblem("error", "ldap->server->port out of range. (Must be 1-65535.)");
                }

                if (!isset($config["ldap"]["server"]["version"])) {
                    $addProblem("warning", "ldap->server->version missing. (Assuming 3.)");
                    $config["ldap"]["server"]["version"] = 3;
                } elseif (!($config["ldap"]["server"]["version"] = intval($config["ldap"]["server"]["version"]))) {
                    $addProblem("warning", "ldap->server->version not specified. (Assuming 3.)");
                    $config["ldap"]["server"]["version"] = 3;
                } elseif ($config["ldap"]["server"]["version"] < 1 || $config["ldap"]["server"]["version"] > 3) {
                    $addProblem("error", "ldap->server->version out of range. (Must be 1-3.)");
                }

                if (!isset($config["ldap"]["server"]["encryption"])) {
                    $addProblem("warning", "ldap->server->encryption missing. (Assuming none.)");
                    $config["ldap"]["server"]["encryption"] = "none";
                } elseif (!($config["ldap"]["server"]["encryption"] = trim($config["ldap"]["server"]["encryption"]))) {
                    $addProblem("warning", "ldap->server->encryption not specified. (Assuming none.)");
                    $config["ldap"]["server"]["encryption"] = "none";
                } else {
                    switch ($config["ldap"]["server"]["encryption"]) {
                        case "none":
                        case "tls":
                            if (!$config["ldap"]["server"]["port"]) {
                                $config["ldap"]["server"]["port"] = 389;
                            }
                            break;

                        case "ssl":
                            if (!$config["ldap"]["server"]["port"]) {
                                $config["ldap"]["server"]["port"] = 636;
                            }
                            break;

                        default:
                            $addProblem(
                                "error",
                                "ldap->server->encryption invalid. (Must be \"none\", \"ssl\", or \"tls\".)"
                            );
                    }
                }

                if (!isset($config["ldap"]["server"]["bindDn"])) {
                    $addProblem("warning", "ldap->server->bindDn missing. (Assuming anonymous access.)");
                    $config["ldap"]["server"]["bindDn"] = null;
                } elseif (!($config["ldap"]["server"]["bindDn"] = trim($config["ldap"]["server"]["bindDn"]))) {
                    $addProblem("warning", "ldap->server->bindDn not specified. (Assuming anonymous access.)");
                    $config["ldap"]["server"]["bindDn"] = null;
                } else {
                    if (!isset($config["ldap"]["server"]["bindPassword"])) {
                        $addProblem(
                            "warning",
                            "ldap->server->bindPassword missing. (Must be specified for non-anonymous access.)"
                        );
                    } elseif (!strlen($config["ldap"]["server"]["bindPassword"])) {
                        $addProblem(
                            "warning",
                            "ldap->server->bindPassword not specified. (Must be specified for non-anonymous access.)"
                        );
                    }
                }
            }
            // >> LDAP server

            // << LDAP queries
            if (!isset($config["ldap"]["queries"])) {
                $addProblem("error", "ldap->queries missing.");
            } else {
                if (!isset($config["ldap"]["queries"]["baseDn"])) {
                    $addProblem("error", "ldap->queries->baseDn missing.");
                } elseif (!($config["ldap"]["queries"]["baseDn"] = trim($config["ldap"]["queries"]["baseDn"]))) {
                    $addProblem("error", "ldap->queries->baseDn not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userDn"])) {
                    $addProblem("error", "ldap->queries->userDn missing.");
                } elseif (!($config["ldap"]["queries"]["userDn"] = trim($config["ldap"]["queries"]["userDn"]))) {
                    // $addProblem("warning", "ldap->queries->userDn not specified.");
                    // This is OK: Users will be looked for from the directory root.
                }

                if (
                    !empty($config["ldap"]["queries"]["baseDn"]) &&
                    !empty($config["ldap"]["queries"]["userDn"]) &&
                    strripos($config["ldap"]["queries"]["userDn"], $config["ldap"]["queries"]["baseDn"]) === (strlen(
                            $config["ldap"]["queries"]["userDn"]
                        ) - strlen($config["ldap"]["queries"]["baseDn"]))
                ) {
                    $addProblem(
                        "warning",
                        "ldap->queries->userDn wrongly ends with ldap->queries->baseDn, this could cause user objects to not be found."
                    );
                }

                if (!isset($config["ldap"]["queries"]["userFilter"])) {
                    $addProblem("error", "ldap->queries->userFilter missing.");
                } elseif (!($config["ldap"]["queries"]["userFilter"] = trim(
                    $config["ldap"]["queries"]["userFilter"]
                ))) {
                    $addProblem("error", "ldap->queries->userFilter not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userUniqueAttribute"])) {
                    $addProblem("error", "ldap->queries->userUniqueAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["userUniqueAttribute"] = trim(
                    $config["ldap"]["queries"]["userUniqueAttribute"]
                ))) {
                    $addProblem("error", "ldap->queries->userUniqueAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userMatchAttribute"])) {
                    $addProblem(
                        "warning",
                        "ldap->queries->userMatchAttribute missing. (Assuming == userUniqueAttribute.)"
                    );
                    $config["ldap"]["queries"]["userMatchAttribute"] = $config["ldap"]["queries"]["userUniqueAttribute"];
                } elseif (!($config["ldap"]["queries"]["userMatchAttribute"] = trim(
                    $config["ldap"]["queries"]["userMatchAttribute"]
                ))) {
                    $addProblem(
                        "warning",
                        "ldap->queries->userMatchAttribute not specified. (Assuming == userUniqueAttribute.)"
                    );
                    $config["ldap"]["queries"]["userMatchAttribute"] = $config["ldap"]["queries"]["userUniqueAttribute"];
                }

                if (!isset($config["ldap"]["queries"]["userNameAttribute"])) {
                    $addProblem("error", "ldap->queries->userNameAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["userNameAttribute"] = trim(
                    $config["ldap"]["queries"]["userNameAttribute"]
                ))) {
                    $addProblem("error", "ldap->queries->userNameAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userEmailAttribute"])) {
                    $addProblem("error", "ldap->queries->userEmailAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["userEmailAttribute"] = trim(
                    $config["ldap"]["queries"]["userEmailAttribute"]
                ))) {
                    $addProblem("error", "ldap->queries->userEmailAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupDn"])) {
                    $addProblem("error", "ldap->queries->groupDn missing.");
                } elseif (!($config["ldap"]["queries"]["groupDn"] = trim($config["ldap"]["queries"]["groupDn"]))) {
                    // $addProblem("error", "ldap->queries->groupDn not specified.");
                    // This is OK: Groups will be looked for from the directory root.
                }

                if (
                    !empty($config["ldap"]["queries"]["baseDn"]) &&
                    !empty($config["ldap"]["queries"]["groupDn"]) &&
                    strripos($config["ldap"]["queries"]["groupDn"], $config["ldap"]["queries"]["baseDn"]) === (strlen(
                            $config["ldap"]["queries"]["groupDn"]
                        ) - strlen($config["ldap"]["queries"]["baseDn"]))
                ) {
                    $addProblem(
                        "warning",
                        "ldap->queries->groupDn wrongly ends with ldap->queries->baseDn, this could cause user objects to not be found."
                    );
                }

                if (!isset($config["ldap"]["queries"]["groupFilter"])) {
                    $addProblem("error", "ldap->queries->groupFilter missing.");
                } elseif (!($config["ldap"]["queries"]["groupFilter"] = trim(
                    $config["ldap"]["queries"]["groupFilter"]
                ))) {
                    $addProblem("error", "ldap->queries->groupFilter not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupUniqueAttribute"])) {
                    $addProblem("error", "ldap->queries->groupUniqueAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["groupUniqueAttribute"] = trim(
                    $config["ldap"]["queries"]["groupUniqueAttribute"]
                ))) {
                    $addProblem("error", "ldap->queries->groupUniqueAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupMemberAttribute"])) {
                    $addProblem("error", "ldap->queries->groupMemberAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["groupMemberAttribute"] = trim(
                    $config["ldap"]["queries"]["groupMemberAttribute"]
                ))) {
                    $addProblem("error", "ldap->queries->groupMemberAttribute not specified.");
                }
            }
            // >> LDAP queries
        }
        // >> LDAP

        // << Gitlab
        if (!isset($config["gitlab"]) || !is_array($config["gitlab"])) {
            $addProblem("error", "gitlab missing.");
        } else {
            if (!isset($config["gitlab"]["debug"])) {
                $addProblem("warning", "gitlab->debug missing. (Assuming false.)");
                $config["gitlab"]["debug"] = false;
            } elseif ("" === $config["gitlab"]["debug"]) {
                $addProblem("warning", "gitlab->debug not specified. (Assuming false.)");
                $config["gitlab"]["debug"] = false;
            } elseif (!is_bool($config["gitlab"]["debug"])) {
                $addProblem("error", "gitlab->debug is not a boolean.");
            }

            // << Gitlab options
            if (!isset($config["gitlab"]["options"]) || !is_array($config["gitlab"]["options"])) {
                $addProblem("error", "gitlab->options missing.");
            } else {
                if (!isset($config["gitlab"]["options"]["userNamesToIgnore"])) {
                    $addProblem("warning", "gitlab->options->userNamesToIgnore missing. (Assuming none.)");
                    $config["gitlab"]["options"]["userNamesToIgnore"] = [];
                } elseif ("" === $config["gitlab"]["options"]["userNamesToIgnore"]) {
                    // $addProblem("warning", "gitlab->options->userNamesToIgnore not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["userNamesToIgnore"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["userNamesToIgnore"])) {
                    $addProblem("error", "gitlab->options->userNamesToIgnore is not an array.");
                } elseif (!empty($config["gitlab"]["options"]["userNamesToIgnore"])) {
                    foreach ($config["gitlab"]["options"]["userNamesToIgnore"] as $i => $userName) {
                        if (!is_string($userName)) {
                            $addProblem(
                                "error",
                                sprintf("gitlab->options->userNamesToIgnore[%d] is not a string.", $i)
                            );
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["userNamesToIgnore"][$i] = trim($userName))) {
                            $addProblem("error", sprintf("gitlab->options->userNamesToIgnore[%d] not specified.", $i));
                            continue;
                        }
                    }
                }

                if (!isset($config["gitlab"]["options"]["groupNamesToIgnore"])) {
                    $addProblem("warning", "gitlab->options->groupNamesToIgnore missing. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesToIgnore"] = [];
                } elseif ("" === $config["gitlab"]["options"]["groupNamesToIgnore"]) {
                    // $addProblem("warning", "gitlab->options->groupNamesToIgnore not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesToIgnore"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["groupNamesToIgnore"])) {
                    $addProblem("error", "gitlab->options->groupNamesToIgnore is not an array.");
                } elseif (!empty($config["gitlab"]["options"]["groupNamesToIgnore"])) {
                    foreach ($config["gitlab"]["options"]["groupNamesToIgnore"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem(
                                "error",
                                sprintf("gitlab->options->groupNamesToIgnore[%d] is not a string.", $i)
                            );
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["groupNamesToIgnore"][$i] = trim($groupName))) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesToIgnore[%d] not specified.", $i));
                            continue;
                        }
                    }
                }

                if (!isset($config["gitlab"]["options"]["createEmptyGroups"])) {
                    $addProblem("warning", "gitlab->options->createEmptyGroups missing. (Assuming false.)");
                    $config["gitlab"]["options"]["createEmptyGroups"] = false;
                } elseif ("" === $config["gitlab"]["options"]["createEmptyGroups"]) {
                    $addProblem("warning", "gitlab->options->createEmptyGroups not specified. (Assuming false.)");
                    $config["gitlab"]["options"]["createEmptyGroups"] = false;
                } elseif (!is_bool($config["gitlab"]["options"]["createEmptyGroups"])) {
                    $addProblem("error", "gitlab->options->createEmptyGroups is not a boolean.");
                }

                if (!isset($config["gitlab"]["options"]["deleteExtraGroups"])) {
                    $addProblem("warning", "gitlab->options->deleteExtraGroups missing. (Assuming false.)");
                    $config["gitlab"]["options"]["deleteExtraGroups"] = false;
                } elseif ("" === $config["gitlab"]["options"]["deleteExtraGroups"]) {
                    $addProblem("warning", "gitlab->options->deleteExtraGroups not specified. (Assuming false.)");
                    $config["gitlab"]["options"]["deleteExtraGroups"] = false;
                } elseif (!is_bool($config["gitlab"]["options"]["deleteExtraGroups"])) {
                    $addProblem("error", "gitlab->options->deleteExtraGroups is not a boolean.");
                }

                if (!isset($config["gitlab"]["options"]["newMemberAccessLevel"])) {
                    $addProblem("warning", "gitlab->options->newMemberAccessLevel missing. (Assuming 30.)");
                    $config["gitlab"]["options"]["newMemberAccessLevel"] = 30;
                } elseif ("" === $config["gitlab"]["options"]["newMemberAccessLevel"]) {
                    $addProblem("warning", "gitlab->options->newMemberAccessLevel not specified. (Assuming 30.)");
                    $config["gitlab"]["options"]["newMemberAccessLevel"] = 30;
                } elseif (!is_int($config["gitlab"]["options"]["newMemberAccessLevel"])) {
                    $addProblem("error", "gitlab->options->newMemberAccessLevel is not an integer.");
                }

                if (!isset($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    // $addProblem("warning", "gitlab->options->groupNamesOfAdministrators missing. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfAdministrators"] = [];
                } elseif ("" === $config["gitlab"]["options"]["groupNamesOfAdministrators"]) {
                    $addProblem(
                        "warning",
                        "gitlab->options->groupNamesOfAdministrators not specified. (Assuming none.)"
                    );
                    $config["gitlab"]["options"]["groupNamesOfAdministrators"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    $addProblem("error", "gitlab->options->groupNamesOfAdministrators is not an array.");
                } elseif (!empty($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    foreach ($config["gitlab"]["options"]["groupNamesOfAdministrators"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem(
                                "error",
                                sprintf("gitlab->options->groupNamesOfAdministrators[%d] is not a string.", $i)
                            );
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["groupNamesOfAdministrators"][$i] = trim($groupName))) {
                            $addProblem(
                                "error",
                                sprintf("gitlab->options->groupNamesOfAdministrators[%d] not specified.", $i)
                            );
                            continue;
                        }
                    }
                }

                if (!isset($config["gitlab"]["options"]["groupNamesOfExternal"])) {
                    $addProblem("warning", "gitlab->options->groupNamesOfExternal missing. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfExternal"] = [];
                } elseif ("" === $config["gitlab"]["options"]["groupNamesOfExternal"]) {
                    // $addProblem("warning", "gitlab->options->groupNamesOfExternal not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfExternal"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["groupNamesOfExternal"])) {
                    $addProblem("error", "gitlab->options->groupNamesOfExternal is not an array.");
                } elseif (!empty($config["gitlab"]["options"]["groupNamesOfExternal"])) {
                    foreach ($config["gitlab"]["options"]["groupNamesOfExternal"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem(
                                "error",
                                sprintf("gitlab->options->groupNamesOfExternal[%d] is not a string.", $i)
                            );
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["groupNamesOfExternal"][$i] = trim($groupName))) {
                            $addProblem(
                                "error",
                                sprintf("gitlab->options->groupNamesOfExternal[%d] not specified.", $i)
                            );
                            continue;
                        }
                    }
                }
            }
            // >> Gitlab options

            // << Gitlab instances
            if (!isset($config["gitlab"]["instances"]) || !is_array($config["gitlab"]["instances"])) {
                $addProblem("error", "gitlab->instances missing.");
            } else {
                foreach (array_keys($config["gitlab"]["instances"]) as $instance) {
                    if (!isset($config["gitlab"]["instances"][$instance]["url"])) {
                        $addProblem("error", sprintf("gitlab->instances->%s->url missing.", $instance));
                    } elseif (!($config["gitlab"]["instances"][$instance]["url"] = trim(
                        $config["gitlab"]["instances"][$instance]["url"]
                    ))) {
                        $addProblem("error", sprintf("gitlab->instances->%s->url not specified.", $instance));
                    }

                    if (!isset($config["gitlab"]["instances"][$instance]["token"])) {
                        $addProblem("error", sprintf("gitlab->instances->%s->token missing.", $instance));
                    } elseif (!($config["gitlab"]["instances"][$instance]["token"] = trim(
                        $config["gitlab"]["instances"][$instance]["token"]
                    ))) {
                        $addProblem("error", sprintf("gitlab->instances->%s->token not specified.", $instance));
                    }
                }
            }
            // >> Gitlab instances
        }

        // >> Gitlab

        return (is_array($problems) && isset($problems["error"]) && is_array(
                $problems["error"]
            ) && empty($problems["error"]));
    }
}
