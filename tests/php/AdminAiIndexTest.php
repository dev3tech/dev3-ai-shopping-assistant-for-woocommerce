<?php
/**
 * Admin DEV3 AI features (drafts only) and the embeddings index + semantic retrieval (mocked).
 *
 * @package Dev3\AiShoppingAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiShoppingAssistant\Ai\Admin_Ai;
use Dev3\AiShoppingAssistant\Chat\Retriever;
use Dev3\AiShoppingAssistant\Core3\Core\Jobs;
use Dev3\AiShoppingAssistant\Core3\Data\Embeddings_Store;
use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Data\Knowledge;
use Dev3\AiShoppingAssistant\Index\Indexer;

class AdminAiIndexTest extends Dev3sa_Base_Case {

	public function test_build_knowledge_returns_drafts_and_saves_nothing() {
		$this->ai_on();
		$this->http[':generateContent'] = $this->gemini(
			array(
				'entries' => array(
					array(
						'title'    => 'Do you ship internationally?',
						'content'  => 'Yes, worldwide. [CONFIRM: delivery times]',
						'type'     => 'shipping',
						'keywords' => 'shipping, international',
					),
					array(
						'title'   => '',
						'content' => 'dropped',
						'type'    => 'faq',
					),
				),
			)
		);
		$out = Admin_Ai::build_knowledge( 5 );
		$this->assertCount( 1, $out['drafts'] );
		$this->assertTrue( $out['drafts'][0]['confirm'] );
		$this->assertSame( 0, Knowledge::count( '' ), 'Nothing saved until accepted' );
		$schema = $this->sent( ':generateContent' )[0]['body']['generationConfig']['responseSchema'];
		$this->assertSame( 1200, $schema['properties']['entries']['items']['properties']['content']['maxLength'] );
	}

	public function test_gaps_use_unanswered_questions_masked() {
		$this->ai_on();
		$conv = Conversations::create();
		Conversations::add_message( $conv['id'], 'user', 'gift cards? mail me at jo@example.com', array( 'intent' => 'unanswered' ) );
		$this->http[':generateContent'] = $this->gemini(
			array(
				'entries' => array(
					array(
						'title'    => 'Do you sell gift cards?',
						'content'  => '[CONFIRM: gift cards]',
						'type'     => 'faq',
						'asked'    => 1,
						'examples' => array( 'gift cards?' ),
					),
				),
			)
		);
		$out = Admin_Ai::gaps( 30 );
		$this->assertSame( 1, $out['drafts'][0]['asked'] );
		$prompt = $this->sent( ':generateContent' )[0]['body']['contents'][0]['parts'][0]['text'];
		$this->assertStringNotContainsString( 'jo@example.com', $prompt );
		$this->assertStringContainsString( 'gift cards', $prompt );
	}

	public function test_gaps_without_questions_skip_the_ai() {
		$this->ai_on();
		$this->assertSame( array(), Admin_Ai::gaps( 30 )['drafts'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_insights_report_is_saved_and_persona_is_a_draft() {
		$this->ai_on();
		$this->assertWPError( Admin_Ai::insights( 30 ) );
		$conv = Conversations::create();
		foreach ( array( 'teapots?', 'do you ship to Spain', 'blue mugs', 'gift ideas' ) as $q ) {
			Conversations::add_message( $conv['id'], 'user', $q );
		}
		$this->http[':generateContent'] = $this->gemini(
			array(
				'headline'         => 'Shoppers want gifts',
				'themes'           => array(
					array(
						'title'   => 'Gifts',
						'share'   => 140,
						'summary' => 'Many ask for gifts',
					),
				),
				'missing_products' => array( 'Gift cards' ),
				'sentiment'        => array(
					'positive' => 3,
					'neutral'  => 1,
					'negative' => 0,
				),
				'actions'          => array( 'Add gift cards' ),
			)
		);
		$report = Admin_Ai::insights( 30 );
		$this->assertSame( 100, $report['themes'][0]['share'], 'Clamped' );
		$this->assertSame( 'Shoppers want gifts', get_option( Admin_Ai::INSIGHTS_OPTION )['headline'] );

		$this->http[':generateContent'] = $this->gemini(
			array(
				'bot_name'        => 'Clay',
				'subtitle'        => 'Your pottery guide',
				'greeting'        => 'Hey!',
				'starters'        => array( 'Track my order', 'Talk to a person' ),
				'away_message'    => 'Back soon',
				'ai_instructions' => 'Be warm.',
			)
		);
		$persona = Admin_Ai::persona( 'warm' );
		$this->assertSame( "Track my order\nTalk to a person", $persona['draft']['starters'] );
		$this->assertNotSame( 'Clay', \Dev3\AiShoppingAssistant\Settings::get( 'bot_name' ), 'Not saved' );
	}

	public function test_index_job_embeds_products_and_semantic_search_finds_them() {
		$this->ai_on();
		$scarf = $this->product( 'Wool Scarf', '40', array( 'description' => 'Warm winter accessory' ) );
		$bowl  = $this->product( 'Ramen Bowl', '20' );
		Knowledge::save(
			array(
				'title'   => 'Washing wool',
				'content' => 'Hand wash cold.',
			)
		);
		// "Warm" texts point one way, everything else the other.
		$this->http[':batchEmbedContents'] = $this->embeddings(
			static fn( $text ) => preg_match( '/warm|wool|cosy|cold/i', $text ) ? array( 1, 0, 0 ) : array( 0, 1, 0 )
		);
		$job = Indexer::start();
		$this->assertSame( 3, $job['total'] );
		Jobs::run( $job['id'] );
		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );
		$this->assertSame( 2, Embeddings_Store::instance()->count( 'product' ) );
		$this->assertSame( array( 'status' => 'fresh' ), Indexer::start(), 'Nothing stale' );

		Retriever::reset();
		$ids = Retriever::products( 'something cosy for cold days', 4, 'storefront' );
		$this->assertSame( $scarf->get_id(), $ids[0], 'Semantic match without shared keywords' );
		$this->assertNotContains( $bowl->get_id(), $ids );
		$this->assertSame( 'Washing wool', Retriever::knowledge( 'how do I clean my warm scarf', 2, 'storefront' )[0]['title'] );

		$status = Indexer::status();
		$this->assertSame( 2, $status['products_indexed'] );
		$this->assertSame( 1, $status['kb_indexed'] );
	}

	public function test_index_requires_ai_and_edits_queue_reindex() {
		$this->assertWPError( Indexer::start() );
		$this->ai_on();
		$p = $this->product( 'Thing', '5' );
		Embeddings_Store::instance()->upsert( 'product', $p->get_id(), array( 1, 0 ), 'old' );
		delete_option( Indexer::DIRTY );
		$p->set_name( 'Thing 2' );
		$p->save();
		$this->assertContains( 'product:' . $p->get_id(), (array) get_option( Indexer::DIRTY ) );
		$this->assertNotFalse( wp_next_scheduled( Indexer::EVENT ) );
		wp_trash_post( $p->get_id() );
		$this->assertNull( Embeddings_Store::instance()->get( 'product', $p->get_id() ) );
	}

	public function test_fuse_ranks_items_in_both_lists_first() {
		$this->assertSame( array( 2, 1, 3 ), Retriever::fuse( array( array( 1, 2 ), array( 2, 3 ) ) ) );
	}
}
