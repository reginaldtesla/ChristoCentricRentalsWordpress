module.exports = function (grunt) {
    // Project configuration.
    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),
        jshint: {
            options: {
                jshintrc: './.jshintrc',
                reporterOutput: ""
            },
            dist: ['js/calentim.js']
        },
        uglify: {
            options: {
                sourceMap: true
            },
            build: {
                src: ['js/calentim.js', 'build/vendor/jquery.hammer.js'],
                dest: 'build/js/calentim.min.js'
            },
            docs: {
                options: {
                    sourceMap: false,
                    compress: {
                        global_defs: {
                            "DEBUG": false
                        },
                        dead_code: true
                    }
                },
                src: [
                    'build/vendor/jquery.min.js',
                    'build/vendor/moment.min.js',
                    'build/js/calentim.min.js',
                    'docs/includes/bootstrap.min.js',
                    'docs/includes/prism.js',
                    'docs/includes/pace.js',
                    'docs/includes/toc.min.js'
                ],
                dest: 'docs/includes/scripts.min.js'
            }
        },
        sass: {
            dist: {
                options: {
                    style: 'compressed',
                    sourcemap: 'none'
                },
                files: {
                    'build/css/calentim.min.css': 'css/calentim.scss',
                    'build/css/calentim-dark.min.css': 'css/calentim-dark.scss',
                    'build/css/calentim-alt.min.css': 'css/calentim-alt.scss',
                    'build/css/calentim-dark-alt.min.css': 'css/calentim-dark-alt.scss'
                }
            }
        },
        postcss: {
            options: {
                processors: [
                    require("autoprefixer")({
                        overrideBrowserslist: ["last 3 versions", "ie > 9", "> 1%"]
                    })
                ]
            },
            files: {
                expand: true,
                cwd: "build/css/",
                dest: "build/css/",
                src: ["calentim.min.css", "calentim-dark.min.css", "calentim-alt.min.css", "calentim-dark-alt.min.css"]
            }
        },
        watch: {
            scripts: {
                files: ['js/calentim.js'],
                tasks: ['jshint', 'uglify']
            },
            styles: {
                files: ['css/*.scss'],
                tasks: ['sass', 'postcss']
            },
            docs: {
                files: ['readme.md', 'docs/includes/template.html'],
                tasks: 'markdown'
            }
        },
        markdown: {
            all: {
                files: [{
                    expand: true,
                    src: 'readme.md',
                    dest: 'docs/',
                    ext: '.html'
                }],
                options: {
                    template: 'docs/includes/template.html',
                    autoTemplate: true,
                    autoTemplateFormat: 'html'
                }
            }
        },
        compress: {
            main: {
                options: {
                    archive: 'output/calentim.zip'
                },
                files: [{
                    src: ['css/**'],
                    dest: '/',
                }, {
                    src: ['build/**'],
                    dest: '/'
                }, {
                    src: ['js/**'],
                    dest: '/'
                }, {
                    src: ['docs/**', '!docs/includes/back.jpg', '!docs/includes/icons/**/*'],
                    dest: '/'
                }, {
                    src: ['tests/*.test.js', 'tests/jasmine.tmpl'],
                    dest: '/'
                }, {
                    src: ['gruntfile.js', '.gitignore', '.jshintrc', 'package.json', 'readme.md', 'CHANGELOG'],
                    dest: '/'
                }, ]
            },
            screenshots: {
                options: {
                    archive: 'output/screenshots.zip'
                },
                files: [{
                    expand: true,
                    cwd: 'toolbox/screenshots/',
                    src: ['*.png', '!inline.png', '!thumbnail.png'],
                    dest: '/'
                }]
            },
        },
        copy: {
            main: {
                expand: true,
                cwd: 'toolbox',
                src: ['inline.png', 'thumbnail.png'],
                dest: 'output/',
            },
        },
        browserSync: {
            dev: {
                bsFiles: {
                    src: [
                        'build/css/*.min.css',
                        'build/js/*.min.js'
                    ]
                },
                options: {
                    watchTask: true,
                    server: {
                        baseDir: "./"
                    },
                    startPath: "docs/single-test.html"
                }
            },
            docs: {
                bsFiles: {
                    src: [
                        'build/css/*.min.css',
                        'build/js/*.min.js',
                        'docs/**/*.css',
                        'docs/**/*.js',
                        '!docs/readme.html'
                    ]
                },
                options: {
                    watchTask: true,
                    server: {
                        baseDir: "./"
                    },
                    startPath: "docs/readme.html",
                    notify: false
                }
            },
            test: {
                bsFiles: {
                    src: [
                        'tests/*.test.js',
                        'tests/output/calentim.html'
                    ]
                },
                options: {
                    watchTask: true,
                    online: false,
                    server: {
                        baseDir: "./"
                    },
                    startPath: "tests/output/calentim.html",
                    notify: false
                }
            },
            browsertest: {
                bsFiles: {
                    src: [
                        'tests/output/calentim.html'
                    ]
                },
                options: {
                    watchTask: false,
                    online: false,
                    browser: ["chrome", "firefox", "internet explorer"],
                    server: {
                        baseDir: "./"
                    },
                    startPath: "tests/output/calentim.html",
                    notify: false,
                    codeSync: false
                }
            }
        },
        karma: {
            test: {
                options: {
                    files: [
                        'build/vendor/jquery.min.js',
                        'build/vendor/moment.min.js',
                        'build/vendor/jquery.hammer.js',
                        'js/calentim.js',
                        'tests/calentim.test.js',
                        'tests/calentim.inline.test.js',
                        'tests/calentim.ranges.test.js'
                    ],
                    basePath: '',
                    frameworks: ['jasmine'],
                    reporters: ['progress', 'code', 'coverage'],
                    preprocessors: {
                        'js/calentim.js': 'coverage'
                    },
                    port: 9876,
                    colors: true,
                    //background: true,
                    //loggers: [{type: 'console'}],
                    autoWatch: true,
                    browsers: ['Chrome' /*,'ChromeMobile'*/ ],
                    singleRun: true,
                    customLaunchers: {
                        ChromeMobile: {
                            base: 'Chrome',
                            flags: [
                                '--window-size=414,736',
                                '--use-mobile-user-agent',
                                '--user-agent="Mozilla/5.0 (Linux; Android 7.0; SM-G930V Build/NRD90M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/59.0.3071.125 Mobile Safari/537.36"'
                            ]
                        }
                    },
                    htmlReporter: {
                        outputFile: 'tests/output/calentim.html',
                        pageTitle: 'calentim Behaviour Tests',
                        groupSuites: true,
                        useCompactStyle: true,
                        useLegacyStyle: true
                    },
                    codeReporter: {
                        outputPath: 'tests/code',
                        testFiles: ['tests/*.test.js'],
                        cssFiles: ['build/css/calentim.min.css']
                    },
                    coverageReporter: {
                        type: 'html',
                        dir: 'tests/coverage/'
                    }
                }
            },
            mobile: {
                options: {
                    files: [
                        'build/vendor/jquery.min.js',
                        'build/vendor/moment.min.js',
                        'build/vendor/jquery.hammer.js',
                        'js/calentim.js',
                        'tests/calentim.test.js',
                        'tests/calentim.inline.test.js',
                        'tests/calentim.ranges.test.js'
                    ],
                    basePath: '',
                    frameworks: ['jasmine'],
                    reporters: ['dots', 'code'],
                    port: 9876,
                    colors: true,
                    //loggers: [{type: 'console'}],
                    autoWatch: true,
                    browsers: ['ChromeMobile'],
                    singleRun: true,
                    customLaunchers: {
                        ChromeMobile: {
                            base: 'Chrome',
                            flags: [
                                '--window-size=414,736',
                                '--use-mobile-user-agent',
                                '--user-agent="Mozilla/5.0 (Linux; Android 4.0.4; Galaxy Nexus Build/IMM76B) AppleWebKit/535.19 (KHTML, like Gecko) Chrome/18.0.1025.133 Mobile Safari/535.19"'
                            ]
                        }
                    },
                    codeReporter: {
                        outputPath: 'tests/code',
                        testFiles: ['tests/*.test.js'],
                        cssFiles: ['build/css/calentim.min.css']
                    }
                }
            },
            tz: {
                options: {
                    files: [
                        'build/vendor/jquery.min.js',
                        'build/vendor/moment.min.js',
                        'build/vendor/jquery.hammer.js',
                        'js/calentim.js',
                        'tests/calentim.test.js',
                        'tests/calentim.inline.test.js',
                        'tests/calentim.ranges.test.js'
                    ],
                    basePath: '',
                    frameworks: ['jasmine'],
                    reporters: ['dots'],
                    port: 9876,
                    colors: true,
                    //loggers: [{type: 'console'}],
                    autoWatch: true,
                    browsers: ['Chrome', 'Firefox', 'IE', 'IE10', 'ChromeMobile'],
                    singleRun: true,
                    customLaunchers: {
                        ChromeMobile: {
                            base: 'Chrome',
                            flags: [
                                '--window-size=414,736',
                                '--use-mobile-user-agent',
                                '--user-agent="Mozilla/5.0 (Linux; U; Android 2.1-update1; en-us; DROIDX Build/VZW) AppleWebKit/530.17 (KHTML, like Gecko) Version/530.17 480X854 motorola DROIDX"'
                            ]
                        },
                        IE10: {
                            base: 'IE',
                            'x-ua-compatible': 'IE=EmulateIE10'
                        }
                    }
                }
            }
        }
    });

    grunt.loadNpmTasks('grunt-postcss');
    grunt.loadNpmTasks('grunt-browser-sync');
    grunt.loadNpmTasks('grunt-contrib-compress');
    grunt.loadNpmTasks('grunt-contrib-copy');
    grunt.loadNpmTasks('grunt-karma');
    grunt.loadNpmTasks('grunt-contrib-jshint');
    grunt.loadNpmTasks('grunt-contrib-sass');
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-watch');
    grunt.loadNpmTasks('grunt-markdown');

    grunt.registerTask('min', ['jshint', 'uglify', 'sass', 'postcss', 'markdown', 'compress', 'copy']);
    grunt.registerTask('default', ['jshint', 'uglify', 'sass', 'postcss', 'markdown', 'compress', 'copy', 'karma:tz']);
    grunt.registerTask('watcher', ['browserSync:dev', 'watch']);
    grunt.registerTask('watchdocs', ['browserSync:docs', 'watch']);
    grunt.registerTask('test', ['browserSync:test', 'watch:test']);
    grunt.registerTask('testbrowsers', ['browserSync:browsertest']);
};