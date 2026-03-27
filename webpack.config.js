const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );
const path = require( 'path' );

// @TODO get version info from plugin main file.
//       For now, using version info from package.json file.
const { version: STOREENGINE_VERSION } = require( './package.json' );

const config = {
	...defaultConfig,
	// performance: {
	//   hints: false,
	// },
	cache: {
		type: 'filesystem',
		allowCollectingMemory: true,
		compression: 'gzip',
		buildDependencies: {
			config: [ __filename ], // invalidate cache when config changes
		},
		//cacheDirectory: path.resolve(__dirname, 'node_modules/.cache'),
	},
	entry: {
		payments: path.resolve( __dirname, 'dev_ssp/payments.js' ),
	},
	output: {
		filename: `[name].js`,
		path: path.resolve( __dirname, 'assets/build' ),
	},
	externals: {
		jquery: 'jQuery',
		$: 'jQuery',
		StoreEngineGlobal: 'StoreEngineGlobal',
	},
	plugins: [ ...defaultConfig.plugins, new CleanWebpackPlugin() ],
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve.alias,
			'@Components': path.resolve(
				__dirname,
				'../storeengine/dev_storeengine/components/'
			),
			'@Containers': path.resolve(
				__dirname,
				'../storeengine/dev_storeengine/containers/'
			),
			'@Frontend': path.resolve( __dirname, '../storeengine/dev_storeengine/frontend/' ),
			'@Global': path.resolve( __dirname, '../storeengine/dev_storeengine/global/' ),
			'@Redux': path.resolve( __dirname, '../storeengine/dev_storeengine/redux/' ),
			'@Utils': path.resolve( __dirname, '../storeengine/dev_storeengine/utils/' ),
			'@Hooks': path.resolve( __dirname, '../storeengine/dev_storeengine/hooks/' ),
			'@Images': path.resolve( __dirname, '../storeengine/assets/images' ),
		},
	},
};

module.exports = config;
