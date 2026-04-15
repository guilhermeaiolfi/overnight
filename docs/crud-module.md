# Building a CRUD Module

This guide walks through building a complete CRUD module from scratch using the Overnight framework. We'll build a **Blog** module with posts, categories, and comments.

## Approaches

| Approach | Backend | Frontend | Status |
|----------|---------|----------|--------|
| [GraphQL API](#graphql-api-approach) | GraphQL extension | Vue.js / React | Documented |
| Server-rendered | Controller + Views | Plates / Latte | Coming soon |

---

# GraphQL API Approach

This approach uses the GraphQL extension for the backend API and a JavaScript frontend (Vue or React) for the UI. The backend generates the full CRUD schema automatically from your entity definitions — no controllers needed.

## What You'll Build

- **Posts** — list with pagination, filtering, sorting; create/edit/delete
- **Categories** — dropdown for post assignment
- **Comments** — nested under posts
- File upload for post cover images
- Validation on all inputs
- Mutation events for timestamps

---

## Step 1: Define Your Entities

Create your ORM definitions. This is the only backend code you need — the GraphQL extension generates everything else.

```php
// config/orm.php

use ON\ORM\Definition\Registry;

$registry = new Registry();

// Categories
$registry->collection('category')
    ->description('Blog post categories')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('name', 'string')
        ->description('Category name')
        ->validation('required|max:100')
        ->end()
    ->field('slug', 'string')
        ->description('URL-friendly slug')
        ->validation('required|max:100')
        ->end()
    ->end();

// Posts
$registry->collection('post')
    ->description('Blog posts')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('title', 'string')
        ->description('Post title')
        ->validation('required|max:255')
        ->end()
    ->field('content', 'text')
        ->description('Post body content')
        ->end()
    ->field('status', 'string')
        ->description('Publication status')
        ->metadata('enum', ['draft', 'published', 'archived'])
        ->end()
    ->field('cover_image', 'image')
        ->description('Cover image path')
        ->nullable(true)
        ->end()
    ->field('category_id', 'int')
        ->description('Category foreign key')
        ->end()
    ->field('created_at', 'datetime')->nullable(true)->end()
    ->field('updated_at', 'datetime')->nullable(true)->end()
    ->belongsTo('category', 'category')
        ->innerKey('category_id')->outerKey('id')
        ->end()
    ->hasMany('comments', 'comment')
        ->innerKey('id')->outerKey('post_id')
        ->end()
    ->end();

// Comments
$registry->collection('comment')
    ->description('Post comments')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('post_id', 'int')->end()
    ->field('author', 'string')
        ->validation('required|max:100')
        ->end()
    ->field('body', 'text')
        ->description('Comment text')
        ->validation('required')
        ->end()
    ->field('created_at', 'datetime')->nullable(true)->end()
    ->belongsTo('post', 'post')
        ->innerKey('post_id')->outerKey('id')
        ->end()
    ->end();
```

## Step 2: Install the Extensions

```php
// config/extensions.php

return [
    \ON\Config\ConfigExtension::class => [],
    \ON\Container\ContainerExtension::class => [],
    \ON\Event\EventsExtension::class => [],
    \ON\DB\DatabaseExtension::class => [],
    \ON\Middleware\PipelineExtension::class => [],
    \ON\Router\RouterExtension::class => [],
    \ON\RateLimit\RateLimitExtension::class => [],
    \ON\GraphQL\GraphQLExtension::class => [
        'path' => '/graphql',
        'resolver' => 'auto',
        'introspection' => true,    // set false in production
        'maxDepth' => 10,
        'maxComplexity' => 100,
        'rateLimit' => 100,
        'rateLimitWindow' => 60,
    ],
];
```

That's it for the backend. The GraphQL extension auto-generates:
- Queries: `post`, `post_by_id`, `category`, `category_by_id`, `comment`, `comment_by_id`
- Mutations: `create_post`, `update_post`, `delete_post` (same for category and comment)
- Connection types with `items` and `totalCount` for pagination
- Input types with validation
- Enum type for post status
- Upload scalar for cover images

## Step 3: Add Mutation Events (Optional)

Handle timestamps and file uploads via events:

```php
// src/Blog/BlogModule.php

use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use ON\GraphQL\Event\BeforeMutation;
use Psr\Http\Message\UploadedFileInterface;

class BlogModule extends AbstractExtension implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'graphql.mutation.before' => 'onBeforeMutation',
        ];
    }

    public function onBeforeMutation(BeforeMutation $event): void
    {
        $input = $event->getInput();
        $operation = $event->getOperation();
        $collection = $event->getCollection()->getName();
        $modified = false;

        // Only handle collections this module owns
        if (!in_array($collection, ['post', 'category', 'comment'])) {
            return;
        }

        // Auto-set timestamps
        if ($operation === 'create') {
            $input['created_at'] = date('Y-m-d H:i:s');
            $input['updated_at'] = date('Y-m-d H:i:s');
            $modified = true;
        } elseif ($operation === 'update') {
            $input['updated_at'] = date('Y-m-d H:i:s');
            $modified = true;
        }

        // Handle file uploads
        foreach ($input as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $filename = uniqid() . '_' . $value->getClientFilename();
                $path = "uploads/{$collection}/{$filename}";
                $value->moveTo($path);
                $input[$key] = $path;
                $modified = true;
            }
        }

        if ($modified) {
            $event->setInput($input);
        }
    }

    // ... standard extension methods
}
```

## Step 4: Frontend Setup

### Install urql

For Vue:
```bash
npm install @urql/vue graphql
npm install @urql/exchange-multipart-fetch  # for file uploads
```

For React:
```bash
npm install urql graphql
npm install @urql/exchange-multipart-fetch
```

### Configure the Client

Vue (`src/main.js`):
```javascript
import { createApp } from 'vue';
import urql, { cacheExchange, fetchExchange } from '@urql/vue';
import { multipartFetchExchange } from '@urql/exchange-multipart-fetch';
import App from './App.vue';

const app = createApp(App);

app.use(urql, {
  url: '/graphql',
  exchanges: [cacheExchange, multipartFetchExchange, fetchExchange],
});

app.mount('#app');
```

React (`src/main.jsx`):
```jsx
import { createClient, cacheExchange, fetchExchange, Provider } from 'urql';
import { multipartFetchExchange } from '@urql/exchange-multipart-fetch';

const client = createClient({
  url: '/graphql',
  exchanges: [cacheExchange, multipartFetchExchange, fetchExchange],
});

root.render(
  <Provider value={client}>
    <App />
  </Provider>
);
```

## Step 5: Frontend — Vue.js Implementation

### GraphQL Operations

```javascript
// src/graphql/posts.js
import { gql } from '@urql/vue';

export const LIST_POSTS = gql`
  query ListPosts($limit: Int, $offset: Int, $sort: String, $order: String, $status: StatusEnum, $title: String) {
    post(limit: $limit, offset: $offset, sort: $sort, order: $order, status: $status, title: $title) {
      items { id title status created_at category { id name } }
      totalCount
    }
  }
`;

export const GET_POST = gql`
  query GetPost($id: ID!) {
    post_by_id(id: $id) {
      id title content status cover_image category_id
      comments { id author body created_at }
    }
  }
`;

export const CREATE_POST = gql`
  mutation CreatePost($input: PostInput!) {
    create_post(input: $input) { id title status }
  }
`;

export const UPDATE_POST = gql`
  mutation UpdatePost($id: ID!, $input: PostUpdateInput!) {
    update_post(id: $id, input: $input) { id title status updated_at }
  }
`;

export const DELETE_POST = gql`
  mutation DeletePost($id: ID!) {
    delete_post(id: $id) { id }
  }
`;

export const LIST_CATEGORIES = gql`
  query ListCategories {
    category { items { id name } totalCount }
  }
`;
```

### Post List View (Vue)

```vue
<!-- src/views/PostList.vue -->
<template>
  <div>
    <h1>Posts</h1>

    <div class="toolbar">
      <input v-model="search" placeholder="Search posts..." @input="debouncedSearch" />
      <select v-model="statusFilter" @change="page = 1">
        <option value="">All statuses</option>
        <option value="DRAFT">Draft</option>
        <option value="PUBLISHED">Published</option>
        <option value="ARCHIVED">Archived</option>
      </select>
      <router-link to="/posts/create">+ New Post</router-link>
    </div>

    <div v-if="fetching">Loading...</div>

    <table v-else>
      <thead>
        <tr>
          <th @click="toggleSort('title')">Title</th>
          <th>Status</th>
          <th>Category</th>
          <th @click="toggleSort('created_at')">Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="post in posts" :key="post.id">
          <td>{{ post.title }}</td>
          <td>{{ post.status }}</td>
          <td>{{ post.category?.name || '—' }}</td>
          <td>{{ formatDate(post.created_at) }}</td>
          <td>
            <router-link :to="`/posts/${post.id}/edit`">Edit</router-link>
            <button @click="removePost(post.id)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="pagination">
      <button :disabled="page <= 1" @click="page--">Previous</button>
      <span>Page {{ page }} of {{ totalPages }}</span>
      <button :disabled="page >= totalPages" @click="page++">Next</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useQuery, useMutation } from '@urql/vue';
import { LIST_POSTS, DELETE_POST } from '../graphql/posts';

const page = ref(1);
const perPage = 20;
const sort = ref('created_at');
const order = ref('DESC');
const search = ref('');
const statusFilter = ref('');

const variables = computed(() => ({
  limit: perPage,
  offset: (page.value - 1) * perPage,
  sort: sort.value,
  order: order.value,
  ...(search.value ? { title: `%${search.value}%` } : {}),
  ...(statusFilter.value ? { status: statusFilter.value } : {}),
}));

const { data, fetching, executeQuery } = useQuery({ query: LIST_POSTS, variables });
const { executeMutation: deletePostMutation } = useMutation(DELETE_POST);

const posts = computed(() => data.value?.post?.items || []);
const totalCount = computed(() => data.value?.post?.totalCount || 0);
const totalPages = computed(() => Math.ceil(totalCount.value / perPage));

function toggleSort(field) {
  if (sort.value === field) {
    order.value = order.value === 'ASC' ? 'DESC' : 'ASC';
  } else {
    sort.value = field;
    order.value = 'ASC';
  }
}

async function removePost(id) {
  if (!confirm('Delete this post?')) return;
  await deletePostMutation({ id: String(id) });
  executeQuery({ requestPolicy: 'network-only' });
}

let searchTimer;
function debouncedSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page.value = 1; }, 300);
}

function formatDate(d) {
  return d ? new Date(d).toLocaleDateString() : '—';
}
</script>
```

### Post Form (Vue)

```vue
<!-- src/views/PostForm.vue -->
<template>
  <div>
    <h1>{{ isEdit ? 'Edit Post' : 'New Post' }}</h1>

    <form @submit.prevent="save">
      <div class="field">
        <label>Title</label>
        <input v-model="form.title" required />
        <span class="error" v-if="errors.title">{{ errors.title[0] }}</span>
      </div>

      <div class="field">
        <label>Content</label>
        <textarea v-model="form.content" rows="10"></textarea>
      </div>

      <div class="field">
        <label>Status</label>
        <select v-model="form.status">
          <option value="DRAFT">Draft</option>
          <option value="PUBLISHED">Published</option>
          <option value="ARCHIVED">Archived</option>
        </select>
      </div>

      <div class="field">
        <label>Category</label>
        <select v-model="form.category_id">
          <option value="">— Select —</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
        </select>
      </div>

      <div class="field">
        <label>Cover Image</label>
        <input type="file" @change="coverFile = $event.target.files[0]" accept="image/*" />
      </div>

      <button type="submit" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      <button type="button" @click="$router.back()">Cancel</button>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useQuery, useMutation } from '@urql/vue';
import { GET_POST, CREATE_POST, UPDATE_POST, LIST_CATEGORIES } from '../graphql/posts';

const route = useRoute();
const router = useRouter();
const isEdit = computed(() => !!route.params.id);

const form = ref({ title: '', content: '', status: 'DRAFT', category_id: '' });
const coverFile = ref(null);
const errors = ref({});
const saving = ref(false);

const { data: catData } = useQuery({ query: LIST_CATEGORIES });
const categories = computed(() => catData.value?.category?.items || []);

const { executeMutation: createPost } = useMutation(CREATE_POST);
const { executeMutation: updatePost } = useMutation(UPDATE_POST);

onMounted(async () => {
  if (isEdit.value) {
    const { data } = await useQuery({ query: GET_POST, variables: { id: route.params.id } });
    if (data.value?.post_by_id) {
      form.value = { ...data.value.post_by_id };
    }
  }
});

async function save() {
  saving.value = true;
  errors.value = {};

  const input = { ...form.value };
  delete input.id;
  delete input.cover_image;
  delete input.comments;
  if (input.category_id) input.category_id = Number(input.category_id);

  try {
    let result;
    if (isEdit.value) {
      result = await updatePost({ id: route.params.id, input });
    } else {
      result = await createPost({ input });
    }

    if (result.error) {
      const ext = result.error.graphQLErrors?.[0]?.extensions;
      if (ext?.validationErrors) {
        errors.value = ext.validationErrors;
        return;
      }
      throw result.error;
    }

    router.push('/posts');
  } catch (err) {
    alert(err.message);
  } finally {
    saving.value = false;
  }
}
</script>
```

## Step 6: React Implementation

### Post List (React)

```jsx
import { useState } from 'react';
import { useQuery, useMutation } from 'urql';
import { LIST_POSTS, DELETE_POST } from '../graphql/posts';

export default function PostList() {
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState('created_at');
  const [order, setOrder] = useState('DESC');
  const [search, setSearch] = useState('');
  const perPage = 20;

  const [result, reexecute] = useQuery({
    query: LIST_POSTS,
    variables: {
      limit: perPage,
      offset: (page - 1) * perPage,
      sort,
      order,
      ...(search ? { title: `%${search}%` } : {}),
    },
  });

  const [, deletePost] = useMutation(DELETE_POST);

  const posts = result.data?.post?.items || [];
  const totalCount = result.data?.post?.totalCount || 0;
  const totalPages = Math.ceil(totalCount / perPage);

  const handleSort = (field) => {
    if (sort === field) setOrder(o => o === 'ASC' ? 'DESC' : 'ASC');
    else { setSort(field); setOrder('ASC'); }
  };

  const handleDelete = async (id) => {
    if (!confirm('Delete?')) return;
    await deletePost({ id: String(id) });
    reexecute({ requestPolicy: 'network-only' });
  };

  if (result.fetching) return <p>Loading...</p>;

  return (
    <div>
      <h1>Posts</h1>
      <input value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="Search..." />
      <a href="/posts/create">+ New Post</a>

      <table>
        <thead>
          <tr>
            <th onClick={() => handleSort('title')}>Title</th>
            <th>Status</th>
            <th>Category</th>
            <th onClick={() => handleSort('created_at')}>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {posts.map(post => (
            <tr key={post.id}>
              <td>{post.title}</td>
              <td>{post.status}</td>
              <td>{post.category?.name || '—'}</td>
              <td>{post.created_at}</td>
              <td>
                <a href={`/posts/${post.id}/edit`}>Edit</a>
                <button onClick={() => handleDelete(post.id)}>Delete</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
      <span>Page {page} of {totalPages}</span>
      <button disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>Next</button>
    </div>
  );
}
```

## Step 7: Error Handling

urql returns errors in the result object. Handle validation errors from the GraphQL extension:

```javascript
// Vue
const result = await createPost({ input });
if (result.error) {
  const ext = result.error.graphQLErrors?.[0]?.extensions;
  if (ext?.code === 'VALIDATION_ERROR') {
    errors.value = ext.validationErrors;
  } else if (ext?.code === 'DUPLICATE') {
    alert(result.error.message);
  }
}

// React
const [result, execute] = useMutation(CREATE_POST);
if (result.error) {
  const ext = result.error.graphQLErrors?.[0]?.extensions;
  // same pattern
}
```

## Summary

| Layer | What You Write | What's Auto-Generated |
|-------|---------------|----------------------|
| Backend entities | `config/orm.php` — field definitions, validation, relations | GraphQL schema, queries, mutations, input types, enums |
| Backend events | `BlogModule.php` — timestamps, file uploads (optional) | Event dispatching in mutation lifecycle |
| Frontend client | urql setup in `main.js` / `main.jsx` | Loading states, caching, error objects |
| Frontend views | Vue/React components with `useQuery` / `useMutation` | — |

The backend is ~50 lines of entity definitions. Everything else — schema generation, CRUD operations, pagination, filtering, sorting, validation, error handling, file uploads — is handled by the framework.

---

## See Also

- [GraphQL Extension](extensions/graphql.md) — Full API reference
- [ORM Entity Definition](orm-entity-definition.md) — Field types, relations, metadata
- [Events Extension](extensions/events.md) — Event subscribers and listeners
- [DataLoader](extensions/graphql-dataloader.md) — N+1 problem solutions